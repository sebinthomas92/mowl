<?php

namespace App\Services;

use App\Exceptions\VertexAIResponseException;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

/**
 * Product page photos are styled scenes: the Kindle stand is shown holding a real book cover and a
 * magazine. The image model copies a reference faithfully, props and their text included, so the banner
 * reference is a once-per-photo copy with every prop removed, kept beside the original.
 */
class BannerReferenceImage
{
    public function __construct(private GoogleVertexAIClient $client, private ProviderCostCalculator $costs) {}

    /**
     * @return array{inlineData: array{mimeType: string, data: string}, cost: float}
     */
    public function for(MediaAsset $asset, string $product): array
    {
        $cached = data_get($asset->derivatives, 'banner_reference');
        if (is_string($cached) && Storage::disk($asset->disk)->exists($cached)) {
            return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(Storage::disk($asset->disk)->get($cached))], 'cost' => 0.0];
        }

        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => "Product photo of {$product}. Recreate this exact product on its own on a plain, softly lit light-grey background, same angle, form, materials, proportions and colour. "
                    .'Remove everything that is not the product itself: books, magazines, devices, screens, plants, cups, hands and any other props. '
                    .'Keep marks printed on the product itself only. No other text, letters, logos or labels anywhere.'],
                ['inlineData' => ['mimeType' => $asset->mime_type, 'data' => base64_encode(Storage::disk($asset->disk)->get($asset->path))]],
            ]]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ], config('campaigns.banners.model'));

        $json = $response->json();
        $encoded = collect(data_get($json, 'candidates.0.content.parts', []))
            ->map(fn (array $part) => data_get($part, 'inlineData.data'))
            ->first(fn (mixed $data) => is_string($data));
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($bytes) || $bytes === '') {
            throw new VertexAIResponseException('vertex_missing_image', 'Vertex AI returned no cleaned product reference image.', true);
        }
        $details = collect(data_get($json, 'usageMetadata.candidatesTokensDetails', []));
        $cost = $this->costs->calculateBanner(
            (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            (int) $details->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'TEXT')->sum('tokenCount'),
            (int) $details->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'IMAGE')->sum('tokenCount'),
        );

        $png = $this->png($bytes);
        if ($asset->exists) {
            $path = dirname($asset->path).'/banner-reference-'.$asset->id.'.png';
            Storage::disk($asset->disk)->put($path, $png);
            $asset->forceFill(['derivatives' => [...($asset->derivatives ?? []), 'banner_reference' => $path]])->save();
        }

        return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)], 'cost' => $cost];
    }

    private function png(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return $bytes;
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
