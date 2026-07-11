<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Models\Product;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAIResponsesCampaignPackGenerator implements CampaignPackGenerator
{
    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $model = config('campaigns.openai.model');
        $userContent = [[
            'type' => 'input_text',
            'text' => $this->sourcePrompt($product, $source, $page),
        ]];
        foreach ($this->visualInputs($page) as $visual) {
            $userContent[] = $visual;
        }
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(120)
            ->post(rtrim(config('campaigns.openai.base_url'), '/').'/responses', [
                'model' => $model,
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
            ])
            ->throw();

        $payload = $response->json();
        $outputText = collect($payload['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->firstWhere('type', 'output_text')['text'] ?? null;

        if (! $outputText) {
            throw new RuntimeException('OpenAI returned no structured campaign-pack output.');
        }

        $generated = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        $evidence = $generated['evidence'] ?? [];
        $flags = $generated['compliance_flags'] ?? [];
        unset($generated['evidence'], $generated['compliance_flags']);

        return new GenerationResult(
            content: $generated,
            evidence: $evidence,
            complianceFlags: $flags,
            provider: 'openai',
            model: $model,
            inputTokens: (int) data_get($payload, 'usage.input_tokens', 0),
            cachedInputTokens: (int) data_get($payload, 'usage.input_tokens_details.cached_tokens', 0),
            outputTokens: (int) data_get($payload, 'usage.output_tokens', 0),
            providerRequestId: $payload['id'] ?? null,
        );
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You create campaign intelligence for ecommerce performance agencies. Use only facts supported by the supplied product-page source. Do not invent materials, certifications, performance outcomes, health claims, guarantees, scarcity, discounts, reviews, or customer results. Every factual marketing claim must have a concise evidence record using an exact source excerpt. Put uncertain or unsupported claims in compliance_flags instead of presenting them as facts. Write copy that is useful to media buyers, concrete, concise, and ready to paste into campaign tools.
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
                $bytes = Storage::disk($item['disk'])->get($item['path']);

                return [
                    'type' => 'input_image',
                    'image_url' => 'data:image/jpeg;base64,'.base64_encode($bytes),
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
