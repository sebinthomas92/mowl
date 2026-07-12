<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class OpenAIResponsesCampaignPackGenerator implements CampaignPackGenerator
{
    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $userContent = [[
            'type' => 'input_text',
            'text' => $this->sourcePrompt($product, $source, $page),
        ]];
        foreach ($this->visualInputs($page) as $visual) {
            $userContent[] = $visual;
        }
        $lastException = null;

        foreach ($this->models() as $model) {
            for ($attempt = 1; $attempt <= config('campaigns.openai.retry_attempts'); $attempt++) {
                try {
                    [$response, $latency] = $this->send($apiKey, $model, $userContent);

                    return $this->resultFromResponse($response, $source, $page, $model, $latency);
                } catch (OpenAIResponseException $exception) {
                    $lastException = $exception;

                    if (! $exception->retryable || $attempt === config('campaigns.openai.retry_attempts')) {
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
                ->timeout(config('campaigns.openai.timeout_seconds'))
                ->post(rtrim(config('campaigns.openai.base_url'), '/').'/responses', $this->payload($model, $userContent));
        } catch (ConnectionException $exception) {
            throw new OpenAIResponseException('openai_connection_error', 'OpenAI connection failed: '.$exception->getMessage(), true);
        }

        if ($response->failed()) {
            throw $this->responseException($response);
        }

        return [$response, (int) round((hrtime(true) - $startedAt) / 1_000_000)];
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
        $this->validateEvidence($generated, $evidence, $flags, $source, $page);
        unset($generated['evidence'], $generated['compliance_flags']);

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

    private function validateEvidence(array $generated, array $evidence, array $flags, SourceSnapshot $source, array $page): void
    {
        $allowedSources = array_filter([$source->url, $page['url'] ?? null, $page['canonical_url'] ?? null]);
        $sourceText = $this->normalize(implode("\n", [
            $page['description'] ?? '',
            $page['content'] ?? '',
            json_encode($page['product_truth'] ?? []),
        ]));
        $flaggedClaims = collect($flags)->pluck('claim')->map(fn ($claim) => $this->normalize((string) $claim));
        $linkedClaims = [];

        foreach ($evidence as $reference) {
            $claim = $this->normalize((string) ($reference['claim'] ?? ''));
            $status = $reference['status'] ?? null;
            if ($claim === '') {
                throw new OpenAIResponseException('openai_invalid_evidence', 'OpenAI returned evidence without a claim.', true);
            }

            if ($status === 'unsupported') {
                if (! $flaggedClaims->contains($claim)) {
                    throw new OpenAIResponseException('openai_unflagged_claim', 'OpenAI returned an unsupported claim without a compliance flag.', true);
                }

                continue;
            }

            $excerpt = $this->normalize((string) ($reference['excerpt'] ?? ''));
            if ($status !== 'source-linked' || ! in_array($reference['source'] ?? null, $allowedSources, true) || $excerpt === '' || ! str_contains($sourceText, $excerpt)) {
                throw new OpenAIResponseException('openai_invalid_evidence', 'OpenAI returned evidence that is not traceable to the supplied source.', true);
            }

            $linkedClaims[] = $claim;
        }

        foreach ($generated['product_truth']['verified_facts'] ?? [] as $fact) {
            if (! in_array($this->normalize((string) $fact), $linkedClaims, true)) {
                throw new OpenAIResponseException('openai_unlinked_fact', 'OpenAI returned a verified fact without a source-linked evidence claim.', true);
            }
        }
    }

    private function validateShape(array $generated): void
    {
        $requiredSections = ['product_truth', 'direction', 'audiences', 'benefits', 'meta', 'hooks', 'script', 'captions', 'shot_log', 'evidence', 'compliance_flags'];

        foreach ($requiredSections as $section) {
            if (! array_key_exists($section, $generated)) {
                throw new OpenAIResponseException('openai_invalid_schema', "OpenAI structured output is missing {$section}.", true);
            }
        }

        if (! is_array($generated['product_truth']) || ! is_array($generated['product_truth']['verified_facts'] ?? null) || ! is_array($generated['evidence']) || ! is_array($generated['compliance_flags'])) {
            throw new OpenAIResponseException('openai_invalid_schema', 'OpenAI structured output has invalid campaign-pack section types.', true);
        }
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You create campaign intelligence for ecommerce performance agencies. Use only facts supported by the supplied product-page source. Do not invent materials, certifications, performance outcomes, health claims, guarantees, scarcity, discounts, reviews, or customer results. Every entry in product_truth.verified_facts must have a source-linked evidence record whose claim is exactly the same text and whose excerpt is copied from the supplied source. Every unsupported claim must have an evidence record with status unsupported and a matching compliance_flags entry; do not present it as a fact. Write copy that is useful to media buyers, concrete, concise, and ready to paste into campaign tools.
PROMPT;
    }

    private function sourcePrompt(Product $product, SourceSnapshot $source, array $page): string
    {
        $parts = [
            "PRODUCT NAME\n{$product->name}",
            "SUPPLIED PRICE\n".($product->price ?: 'Not supplied'),
            "PRODUCT CONTEXT\n".($product->summary ?: 'Not supplied'),
            "SOURCE URL\n{$source->url}",
            'EXTRACTED STRUCTURED TRUTH'."\n".json_encode($page['product_truth'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            "SOURCE DESCRIPTION\n".($page['description'] ?? ''),
            "NORMALIZED SOURCE CONTENT\n".mb_substr($page['content'] ?? '', 0, 50_000),
            'MEDIA ANALYSIS'."\n".json_encode([
                'assets' => $page['media_analysis']['assets'] ?? [],
                'transcripts' => $page['media_analysis']['transcripts'] ?? [],
                'frame_count' => count($page['media_analysis']['frames'] ?? []),
                'image_count' => count($page['media_analysis']['images'] ?? []),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];

        if ($section = $page['regeneration_section'] ?? null) {
            $parts[] = "REGENERATION REQUEST\nCreate a materially different {$section} section while preserving every other section's meaning. Avoid unsupported claims and repetition.";
            $parts[] = 'CURRENT CAMPAIGN PACK'."\n".json_encode($page['current_content'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n\n", $parts);
    }

    private function visualInputs(array $page): array
    {
        $frames = array_slice($page['media_analysis']['frames'] ?? [], 0, config('campaigns.media.max_frames'));
        $images = array_slice($page['media_analysis']['images'] ?? [], 0, 4);

        return collect(array_merge($images, $frames))
            ->filter(fn (array $item) => isset($item['disk'], $item['path']) && Storage::disk($item['disk'])->exists($item['path']))
            ->map(function (array $item): array {
                $disk = Storage::disk($item['disk']);
                $bytes = $disk->get($item['path']);
                $mimeType = $disk->mimeType($item['path']) ?: 'image/jpeg';

                return [
                    'type' => 'input_image',
                    'image_url' => "data:{$mimeType};base64,".base64_encode($bytes),
                ];
            })
            ->values()
            ->all();
    }

    private function schema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['product_truth', 'direction', 'audiences', 'benefits', 'meta', 'hooks', 'script', 'captions', 'shot_log', 'evidence', 'compliance_flags'],
            'properties' => [
                'product_truth' => $this->objectSchema([
                    'name' => ['type' => 'string'],
                    'price' => ['type' => 'string'],
                    'source' => ['type' => 'string'],
                    'verified_facts' => $stringArray,
                ]),
                'direction' => $this->objectSchema([
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ]),
                'audiences' => $stringArray,
                'benefits' => $stringArray,
                'meta' => $this->objectSchema([
                    'primary_text' => ['type' => 'string'],
                    'headlines' => $stringArray,
                    'descriptions' => $stringArray,
                ]),
                'hooks' => $stringArray,
                'script' => [
                    'type' => 'array',
                    'items' => $this->objectSchema(['time' => ['type' => 'string'], 'line' => ['type' => 'string']]),
                ],
                'captions' => $stringArray,
                'shot_log' => $stringArray,
                'evidence' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'claim' => ['type' => 'string'],
                        'source' => ['type' => 'string'],
                        'excerpt' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['source-linked', 'unsupported']],
                    ]),
                ],
                'compliance_flags' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'blocked']],
                        'claim' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ]),
                ],
            ],
        ];
    }

    private function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }
}
