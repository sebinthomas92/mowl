<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Exceptions\VertexAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Support\GenerationDeadline;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use JsonException;

class GoogleVertexAICampaignPackGenerator implements CampaignPackGenerator
{
    public function __construct(private GoogleVertexAIClient $vertex) {}

    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= config('campaigns.google.retry_attempts'); $attempt++) {
            if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                throw new VertexAIResponseException('vertex_time_budget', 'Generation stopped to stay inside the request time limit.'.($lastException ? ' Last error: '.$lastException->getMessage() : ''), true);
            }
            try {
                [$response, $latency] = $this->vertex->generateContent($this->payload($product, $source, $page));

                return $this->resultFromResponse($response, $source, $page, $latency);
            } catch (VertexAIResponseException $exception) {
                $lastException = $exception;
                Log::warning('Vertex AI campaign-pack attempt failed.', ['attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
                if (! $exception->retryable || $attempt === config('campaigns.google.retry_attempts')) {
                    throw $exception;
                }
                usleep($this->retryDelay($attempt) * 1000);
            }
        }

        throw $lastException;
    }

    private function payload(Product $product, SourceSnapshot $source, array $page): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => $this->instructions()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $this->sourcePrompt($product, $source, $page)]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schema(),
            ],
        ];
    }

    private function resultFromResponse(Response $response, SourceSnapshot $source, array $page, int $latency): GenerationResult
    {
        $payload = $response->json();
        $finishReason = data_get($payload, 'candidates.0.finishReason');
        if ($finishReason && $finishReason !== 'STOP') {
            throw new VertexAIResponseException(
                'vertex_incomplete_response',
                "Vertex AI did not complete the campaign-pack response ({$finishReason}).",
                $finishReason === 'MAX_TOKENS' || $finishReason === 'OTHER',
            );
        }

        $outputText = collect(data_get($payload, 'candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode('');
        if ($outputText === '') {
            throw new VertexAIResponseException('vertex_missing_structured_output', 'Vertex AI returned no structured campaign-pack output.', true);
        }

        try {
            $generated = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VertexAIResponseException('vertex_invalid_json', 'Vertex AI returned invalid structured JSON.', true);
        }

        $this->validateShape($generated);
        $evidence = $generated['evidence'] ?? [];
        $flags = $generated['compliance_flags'] ?? [];
        unset($generated['evidence'], $generated['compliance_flags']);
        $checked = app(CampaignPackEvidence::class)->check($generated, $evidence, $flags, $source, $page);
        if (is_string($checked)) {
            throw new VertexAIResponseException('vertex_'.$checked, 'Vertex AI '.match ($checked) {
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
            provider: 'google',
            model: $payload['modelVersion'] ?? config('campaigns.google.model'),
            inputTokens: (int) data_get($payload, 'usageMetadata.promptTokenCount', 0),
            cachedInputTokens: (int) data_get($payload, 'usageMetadata.cachedContentTokenCount', 0),
            outputTokens: (int) data_get($payload, 'usageMetadata.candidatesTokenCount', 0),
            providerRequestId: $response->header('x-request-id'),
            providerLatencyMs: $latency,
        );
    }

    private function validateShape(array $generated): void
    {
        if ($error = CampaignPackBlueprint::shapeError($generated)) {
            throw new VertexAIResponseException('vertex_invalid_schema', 'Vertex AI '.$error, true);
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

    private function retryDelay(int $attempt): int
    {
        return config('campaigns.google.retry_backoff_ms')[$attempt - 1] ?? 0;
    }
}
