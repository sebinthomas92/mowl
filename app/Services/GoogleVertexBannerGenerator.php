<?php

namespace App\Services;

use App\Contracts\BannerGenerator;
use App\Data\BannerGenerationResult;
use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\BannerCreative;
use App\Models\MediaAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class GoogleVertexBannerGenerator implements BannerGenerator
{
    public function __construct(private GoogleVertexAIClient $client, private BannerImageInspector $inspector, private ProviderCostCalculator $costs) {}

    /**
     * Generates the background, then rejects and regenerates any image with readable third-party
     * text or marks (a real book cover on the e-reader, a magazine spine). Every round's image tokens
     * are kept, and inspections are priced at the inspector model's own rates.
     */
    public function generate(BannerCreative $creative, Collection $productImages): BannerGenerationResult
    {
        $attempts = max(1, (int) config('campaigns.banners.inspection_attempts'));
        $product = (string) ($creative->campaignPack?->product?->name ?? '');
        $brand = (string) ($creative->campaignPack?->product?->brand?->name ?? '');
        $usage = [0, 0, 0];
        $inspectionCost = 0.0;
        $marks = [];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $this->generateOnce($creative, $productImages);
                $usage = [$usage[0] + $result->inputTokens, $usage[1] + $result->outputTextTokens, $usage[2] + $result->outputImageTokens];
                if (! config('campaigns.banners.inspect_images')) {
                    return $result;
                }
                $inspection = $this->inspector->inspect($result->imageBytes, $result->mimeType, $product, $brand);
            } catch (VertexAIResponseException $exception) {
                // Images already generated in this attempt were paid for, even when a later call fails.
                $spent = $this->spent($usage, $inspectionCost);
                if ($spent > 0) {
                    throw new BannerAttemptFailed($exception->errorCode, $exception->getMessage(), $spent);
                }

                throw $exception;
            }
            $inspectionCost += $this->costs->calculate((string) config('campaigns.google.model'), $inspection['input_tokens'], 0, $inspection['output_tokens']);
            $marks = $inspection['marks'];
            if ($marks === []) {
                return new BannerGenerationResult(
                    imageBytes: $result->imageBytes,
                    mimeType: $result->mimeType,
                    provider: $result->provider,
                    model: $result->model,
                    inputTokens: $usage[0],
                    outputTextTokens: $usage[1],
                    outputImageTokens: $usage[2],
                    providerRequestId: $result->providerRequestId,
                    providerLatencyMs: $result->providerLatencyMs,
                    additionalCost: round($inspectionCost, 6),
                );
            }
        }

        throw new BannerAttemptFailed('banner_third_party_text', 'Every generated background showed readable third-party text or marks: '.mb_substr(implode(', ', $marks), 0, 300), $this->spent($usage, $inspectionCost));
    }

    private function spent(array $usage, float $inspectionCost): float
    {
        return round($this->costs->calculateBanner($usage[0], $usage[1], $usage[2]) + $inspectionCost, 6);
    }

    private function generateOnce(BannerCreative $creative, Collection $productImages): BannerGenerationResult
    {
        $parts = [['text' => $creative->prompt]];
        foreach ($productImages->take((int) config('campaigns.banners.max_input_images')) as $asset) {
            $parts[] = $this->imagePart($asset);
        }

        [$response, $latency] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => config('campaigns.banners.aspect_ratio'),
                    'imageSize' => config('campaigns.banners.image_size'),
                ],
            ],
        ], config('campaigns.banners.model'));

        $json = $response->json();
        $image = collect(data_get($json, 'candidates.0.content.parts', []))
            ->first(fn (array $part) => is_string(data_get($part, 'inlineData.data')));
        $encoded = data_get($image, 'inlineData.data');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($bytes) || $bytes === '') {
            throw new VertexAIResponseException('vertex_missing_image', 'Vertex AI returned no generated banner image.', true);
        }

        $outputDetails = collect(data_get($json, 'usageMetadata.candidatesTokensDetails', []));
        $imageTokens = (int) $outputDetails
            ->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'IMAGE')
            ->sum('tokenCount');
        $textTokens = (int) $outputDetails
            ->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'TEXT')
            ->sum('tokenCount');
        if ($outputDetails->isEmpty()) {
            $textTokens = (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0);
        }

        return new BannerGenerationResult(
            imageBytes: $bytes,
            mimeType: (string) data_get($image, 'inlineData.mimeType', 'image/png'),
            provider: 'google',
            model: (string) config('campaigns.banners.model'),
            inputTokens: (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            outputTextTokens: $textTokens,
            outputImageTokens: $imageTokens,
            providerRequestId: $response->header('x-request-id') ?: data_get($json, 'responseId'),
            providerLatencyMs: $latency,
        );
    }

    private function imagePart(MediaAsset $asset): array
    {
        $bytes = Storage::disk($asset->disk)->get($asset->path);

        return ['inlineData' => [
            'mimeType' => $asset->mime_type,
            'data' => base64_encode($bytes),
        ]];
    }
}
