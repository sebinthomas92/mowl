<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Exceptions\VertexAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use Illuminate\Http\Client\Response;
use JsonException;

class GoogleVertexAICampaignPackGenerator implements CampaignPackGenerator
{
    public function __construct(private GoogleVertexAIClient $vertex) {}

    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= config('campaigns.google.retry_attempts'); $attempt++) {
            try {
                [$response, $latency] = $this->vertex->generateContent($this->payload($product, $source, $page));

                return $this->resultFromResponse($response, $source, $page, $latency);
            } catch (VertexAIResponseException $exception) {
                $lastException = $exception;
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
        $this->validateEvidence($generated, $evidence, $flags, $source, $page);
        unset($generated['evidence'], $generated['compliance_flags']);

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
                throw new VertexAIResponseException('vertex_invalid_evidence', 'Vertex AI returned evidence without a claim.', true);
            }

            if (in_array($status, ['too_specific_for_evidence', 'unsupported', 'contradicted_by_source'], true)) {
                if (! $flaggedClaims->contains($claim)) {
                    throw new VertexAIResponseException('vertex_unflagged_claim', 'Vertex AI returned an unsafe claim without a compliance flag.', true);
                }

                continue;
            }

            $excerpt = $this->normalize((string) ($reference['excerpt'] ?? ''));
            if (! in_array($status, ['directly_supported', 'supported_paraphrased'], true) || ! in_array($reference['source'] ?? null, $allowedSources, true) || $excerpt === '' || ! str_contains($sourceText, $excerpt)) {
                throw new VertexAIResponseException('vertex_invalid_evidence', 'Vertex AI returned evidence that is not traceable to the supplied source.', true);
            }

            $linkedClaims[(string) ($reference['id'] ?? '')] = $claim;
        }

        foreach ($generated['product_truth']['verified_facts'] ?? [] as $fact) {
            $claimId = (string) ($fact['claim_id'] ?? '');
            if ($claimId === '' || ($linkedClaims[$claimId] ?? null) !== $this->normalize((string) ($fact['statement'] ?? ''))) {
                throw new VertexAIResponseException('vertex_unlinked_fact', 'Vertex AI returned a verified fact without source-linked evidence.', true);
            }
        }
    }

    private function validateShape(array $generated): void
    {
        if ($error = CampaignPackBlueprint::shapeError($generated)) {
            throw new VertexAIResponseException('vertex_invalid_schema', 'Vertex AI '.$error, true);
        }
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
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
            'EXTRACTED STRUCTURED TRUTH'."\n".json_encode($page['product_truth'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            "SOURCE DESCRIPTION\n".($page['description'] ?? ''),
            "NORMALIZED SOURCE CONTENT\n".mb_substr($page['content'] ?? '', 0, 50_000),
        ];

        if ($section = $page['regeneration_section'] ?? null) {
            $parts[] = "REGENERATION REQUEST\nCreate a materially different {$section} section while preserving every other section's meaning. Avoid unsupported claims and repetition.";
            $parts[] = 'CURRENT CAMPAIGN PACK'."\n".json_encode($page['current_content'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
