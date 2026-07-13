<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Exceptions\VertexAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
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
                'parts' => [
                    ['text' => $this->sourcePrompt($product, $source, $page)],
                    ...$this->visualInputs($page),
                ],
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

            if ($status === 'unsupported') {
                if (! $flaggedClaims->contains($claim)) {
                    throw new VertexAIResponseException('vertex_unflagged_claim', 'Vertex AI returned an unsupported claim without a compliance flag.', true);
                }

                continue;
            }

            $excerpt = $this->normalize((string) ($reference['excerpt'] ?? ''));
            if ($status !== 'source-linked' || ! in_array($reference['source'] ?? null, $allowedSources, true) || $excerpt === '' || ! str_contains($sourceText, $excerpt)) {
                throw new VertexAIResponseException('vertex_invalid_evidence', 'Vertex AI returned evidence that is not traceable to the supplied source.', true);
            }

            $linkedClaims[] = $claim;
        }

        foreach ($generated['product_truth']['verified_facts'] ?? [] as $fact) {
            if (! in_array($this->normalize((string) $fact), $linkedClaims, true)) {
                throw new VertexAIResponseException('vertex_unlinked_fact', 'Vertex AI returned a verified fact without source-linked evidence.', true);
            }
        }
    }

    private function validateShape(array $generated): void
    {
        $requiredSections = ['product_truth', 'direction', 'audiences', 'benefits', 'meta', 'hooks', 'script', 'captions', 'shot_log', 'evidence', 'compliance_flags'];

        foreach ($requiredSections as $section) {
            if (! array_key_exists($section, $generated)) {
                throw new VertexAIResponseException('vertex_invalid_schema', "Vertex AI structured output is missing {$section}.", true);
            }
        }

        if (! is_array($generated['product_truth']) || ! is_array($generated['product_truth']['verified_facts'] ?? null) || ! is_array($generated['evidence']) || ! is_array($generated['compliance_flags'])) {
            throw new VertexAIResponseException('vertex_invalid_schema', 'Vertex AI structured output has invalid campaign-pack section types.', true);
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
                'video_count' => count($page['media_analysis']['videos'] ?? []),
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
        $videos = array_slice($page['media_analysis']['videos'] ?? [], 0, 2);

        return collect(array_merge($images, $frames, $videos))
            ->filter(fn (array $item) => isset($item['disk'], $item['path']) && Storage::disk($item['disk'])->exists($item['path']))
            ->map(function (array $item): array {
                $disk = Storage::disk($item['disk']);
                $mimeType = $item['mime_type'] ?? $disk->mimeType($item['path']) ?: 'application/octet-stream';

                if ($item['disk'] === 'gcs') {
                    $bucket = config('filesystems.disks.gcs.bucket');
                    $prefix = trim((string) config('filesystems.disks.gcs.path_prefix'), '/');
                    $path = ltrim(implode('/', array_filter([$prefix, $item['path']])), '/');

                    return ['fileData' => [
                        'mimeType' => $mimeType,
                        'fileUri' => "gs://{$bucket}/{$path}",
                    ]];
                }

                return ['inlineData' => [
                    'mimeType' => $mimeType,
                    'data' => base64_encode($disk->get($item['path'])),
                ]];
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

    private function retryDelay(int $attempt): int
    {
        return config('campaigns.google.retry_backoff_ms')[$attempt - 1] ?? 0;
    }
}
