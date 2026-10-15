<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Support\GenerationDeadline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

class OpenAIResponsesCampaignPackGenerator implements CampaignPackGenerator
{
    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured. Set an OpenAI key or use Vercel AI Gateway with deployment OIDC.');
        }

        $userContent = [[
            'type' => 'input_text',
            'text' => $this->sourcePrompt($product, $source, $page),
        ]];
        $lastException = null;

        foreach ($this->models() as $model) {
            for ($attempt = 1; $attempt <= config('campaigns.openai.retry_attempts'); $attempt++) {
                if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                    throw new OpenAIResponseException('openai_time_budget', 'Generation stopped to stay inside the request time limit.'.($lastException ? ' Last error: '.$lastException->getMessage() : ''), true);
                }
                try {
                    [$response, $latency] = $this->send($apiKey, $model, $userContent);

                    return $this->resultFromResponse($response, $source, $page, $model, $latency);
                } catch (OpenAIResponseException $exception) {
                    $lastException = $exception;
                    Log::warning('OpenAI campaign-pack attempt failed.', ['model' => $model, 'attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);

                    if (! $exception->retryable) {
                        throw $exception;
                    }

                    if ($attempt === config('campaigns.openai.retry_attempts')) {
                        break;
                    }

                    usleep($this->retryDelay($attempt) * 1000);
                }
            }
        }

        throw $lastException ?? new RuntimeException('OpenAI generation failed before a response was received.');
    }

    private function send(string $apiKey, string $model, array $userContent): array
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(app(GenerationDeadline::class)->timeout(config('campaigns.openai.timeout_seconds')))
                ->post(rtrim(config('campaigns.openai.base_url'), '/').'/responses', $this->payload($model, $userContent));
        } catch (ConnectionException $exception) {
            throw new OpenAIResponseException('openai_connection_error', 'OpenAI connection failed: '.$exception->getMessage(), true);
        }

        if ($response->failed()) {
            throw $this->responseException($response);
        }

        return [$response, (int) round((hrtime(true) - $startedAt) / 1_000_000)];
    }

    private function apiKey(): ?string
    {
        if ($apiKey = config('services.openai.api_key')) {
            return $apiKey;
        }

        if (! str_starts_with(rtrim(config('campaigns.openai.base_url'), '/'), 'https://ai-gateway.vercel.sh')) {
            return null;
        }

        return config('services.ai_gateway.api_key')
            ?: config('services.ai_gateway.oidc_token')
            ?: request()->header('x-vercel-oidc-token');
    }

    private function payload(string $model, array $userContent): array
    {
        return [
            'model' => $model,
            'store' => false,
            'reasoning' => ['effort' => config('campaigns.openai.reasoning_effort')],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->instructions(),
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'campaign_pack_content',
                    'description' => 'Evidence-linked ecommerce campaign pack content.',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
        ];
    }

    private function resultFromResponse(Response $response, SourceSnapshot $source, array $page, string $model, int $latency): GenerationResult
    {
        $payload = $response->json();
        if (($payload['status'] ?? null) === 'incomplete') {
            throw new OpenAIResponseException('openai_incomplete_response', 'OpenAI returned an incomplete campaign-pack response.', true);
        }

        $content = collect($payload['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? []);
        if ($content->contains(fn (array $item) => isset($item['refusal']))) {
            throw new OpenAIResponseException('openai_refusal', 'OpenAI declined the campaign-pack request: '.($content->first(fn (array $item) => isset($item['refusal']))['refusal'] ?? 'Unknown refusal'), false);
        }

        $outputText = $content->firstWhere('type', 'output_text')['text'] ?? null;
        if (! $outputText) {
            throw new OpenAIResponseException('openai_missing_structured_output', 'OpenAI returned no structured campaign-pack output.', true);
        }

        try {
            $generated = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenAIResponseException('openai_invalid_json', 'OpenAI returned invalid structured JSON.', true);
        }

        $this->validateShape($generated);
        $evidence = $generated['evidence'] ?? [];
        $flags = $generated['compliance_flags'] ?? [];
        unset($generated['evidence'], $generated['compliance_flags']);
        $checked = app(CampaignPackEvidence::class)->check($generated, $evidence, $flags, $source, $page);
        if (is_string($checked)) {
            throw new OpenAIResponseException('openai_'.$checked, 'OpenAI '.match ($checked) {
                'empty_claim' => 'returned evidence without a claim.',
                'unflagged_claim' => 'returned an unsafe claim without a compliance flag.',
                default => 'returned no evidence that is traceable to the supplied source.',
            }, true);
        }
        [$generated, $evidence] = $checked;

        return new GenerationResult(
            content: $generated,
            evidence: $evidence,
            complianceFlags: $flags,
            provider: 'openai',
            model: $payload['model'] ?? $model,
            inputTokens: (int) data_get($payload, 'usage.input_tokens', 0),
            cachedInputTokens: (int) data_get($payload, 'usage.input_tokens_details.cached_tokens', 0),
            outputTokens: (int) data_get($payload, 'usage.output_tokens', 0),
            providerRequestId: $response->header('x-request-id') ?: ($payload['id'] ?? null),
            providerLatencyMs: $latency,
        );
    }

    private function responseException(Response $response): OpenAIResponseException
    {
        $status = $response->status();
        $message = data_get($response->json(), 'error.message', "OpenAI returned HTTP {$status}.");

        return new OpenAIResponseException(
            "openai_http_{$status}",
            "OpenAI request failed ({$status}): {$message}",
            $status === 408 || $status === 409 || $status === 429 || $status >= 500,
        );
    }

    private function models(): array
    {
        return array_values(array_unique(array_filter([
            config('campaigns.openai.model'),
            ...config('campaigns.openai.fallback_models'),
        ])));
    }

    private function retryDelay(int $attempt): int
    {
        return config('campaigns.openai.retry_backoff_ms')[$attempt - 1] ?? 0;
    }

    private function validateShape(array $generated): void
    {
        if ($error = CampaignPackBlueprint::shapeError($generated)) {
            throw new OpenAIResponseException('openai_invalid_schema', 'OpenAI '.$error, true);
        }
    }

    private function instructions(): string
    {
        return CampaignPackBlueprint::instructions();
    }

    private function sourcePrompt(Product $product, SourceSnapshot $source, array $page): string
    {
        $parts = [
            "PRODUCT NAME\n{$product->name}",
            "SUPPLIED PRICE\n".($product->price ?: 'Not supplied'),
            "PRODUCT CONTEXT\n".($product->summary ?: 'Not supplied'),
            "SOURCE URL\n{$source->url}",
            'EXTRACTED STRUCTURED TRUTH'."\n".CampaignPackEvidence::json($page['product_truth'] ?? [], JSON_PRETTY_PRINT),
            "SOURCE DESCRIPTION\n".($page['description'] ?? ''),
            "NORMALIZED SOURCE CONTENT\n".mb_substr($page['content'] ?? '', 0, 50_000),
        ];

        if ($team = $page['team_knowledge'] ?? null) {
            $parts[] = 'TEAM-CONFIRMED FACTS'."\n".CampaignPackEvidence::json($team, JSON_PRETTY_PRINT);
        }

        if ($brand = $page['brand_profile'] ?? null) {
            $parts[] = 'BRAND VOICE AND PALETTE (style guidance for tone and colour, not product facts)'."\n".CampaignPackEvidence::json($brand, JSON_PRETTY_PRINT);
        }

        if ($brief = $page['campaign_brief'] ?? null) {
            $parts[] = 'CAMPAIGN BRIEF (the team\'s direction for angle and emphasis; never a source of product facts, prices, offers or claims)'."\n".CampaignPackEvidence::json($brief, JSON_PRETTY_PRINT);
        }

        if ($section = $page['regeneration_section'] ?? null) {
            $parts[] = "REGENERATION REQUEST\nCreate a materially different {$section} section while preserving every other section's meaning. Avoid unsupported claims and repetition.";
            $parts[] = 'CURRENT CAMPAIGN PACK'."\n".CampaignPackEvidence::json($page['current_content'] ?? [], JSON_PRETTY_PRINT);
        }

        return implode("\n\n", $parts);
    }

    private function schema(): array
    {
        return CampaignPackBlueprint::schema();
    }
}
