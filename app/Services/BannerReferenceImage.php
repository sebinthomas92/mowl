<?php

namespace App\Services;

use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\MediaAsset;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Product page photos are styled scenes: the Kindle stand is shown holding a real book cover and a
 * magazine. The image model copies a reference faithfully, props and their text included, so the banner
 * reference is a once-per-photo copy with every prop removed, kept beside the original.
 */
class BannerReferenceImage
{
    /** Bump when the cleanup prompt changes, so stored copies made by the old prompt are redone. */
    private const PROMPT_VERSION = 1;

    public function __construct(private GoogleVertexAIClient $client, private ProviderCostCalculator $costs) {}

    /**
     * Banners for one pack run side by side, so the copy is made under a per-photo lock: the others
     * wait up to $waitSeconds and reuse it instead of paying for their own (possibly different) copy.
     *
     * @return array{inlineData: array{mimeType: string, data: string}, cost: float}
     */
    public function for(MediaAsset $asset, string $product, int $timeoutSeconds, int $waitSeconds): array
    {
        if (! $asset->exists) {
            return $this->make($asset, $product, $timeoutSeconds);
        }
        $key = self::key($product);
        if ($cached = $this->cached($asset, $key)) {
            return $cached;
        }

        try {
            return Cache::lock('banner-reference.'.$asset->id, $timeoutSeconds + 30)->block(max(1, $waitSeconds), function () use ($asset, $product, $timeoutSeconds, $key) {
                if ($cached = $this->cached($asset->refresh(), $key)) {
                    return $cached;
                }
                $clean = $this->make($asset, $product, $timeoutSeconds);
                $path = dirname($asset->path).'/banner-reference-'.$asset->id.'.png';
                // The copy is already paid for: a failed write (false, or a throw on the gcs disk) only
                // means it is used once and not recorded.
                try {
                    if (Storage::disk($asset->disk)->put($path, base64_decode($clean['inlineData']['data'])) !== false) {
                        $asset->forceFill(['derivatives' => [...($asset->derivatives ?? []), 'banner_reference' => $path, 'banner_reference_key' => $key]])->save();
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }

                return $clean;
            });
        } catch (LockTimeoutException) {
            throw new VertexAIResponseException('banner_reference_busy', 'Another banner is still preparing the clean product photo.', true);
        }
    }

    /** The copy depends on the product name in the prompt, so a renamed product gets a new one. */
    public static function key(string $product): string
    {
        return substr(sha1(self::PROMPT_VERSION."\n".$product), 0, 16);
    }

    /** @return array{inlineData: array{mimeType: string, data: string}, cost: float}|null */
    private function cached(MediaAsset $asset, string $key): ?array
    {
        $cached = data_get($asset->derivatives, 'banner_reference');
        if (! is_string($cached) || data_get($asset->derivatives, 'banner_reference_key') !== $key || ! Storage::disk($asset->disk)->exists($cached)) {
            return null;
        }

        return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(Storage::disk($asset->disk)->get($cached))], 'cost' => 0.0];
    }

    /** @return array{inlineData: array{mimeType: string, data: string}, cost: float} */
    private function make(MediaAsset $asset, string $product, int $timeoutSeconds): array
    {
        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => "Product photo of {$product}. Recreate this exact product on its own on a plain, softly lit light-grey background, same angle, form, materials, proportions and colour. "
                    .'Remove everything that is not the product itself: books, magazines, devices, screens, plants, cups, hands and any other props. '
                    .'Keep marks printed on the product itself only. No other text, letters, logos or labels anywhere.'],
                ['inlineData' => ['mimeType' => $asset->mime_type, 'data' => base64_encode(Storage::disk($asset->disk)->get($asset->path))]],
            ]]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ], config('campaigns.banners.model'), $timeoutSeconds);

        $json = $response->json();
        $details = collect(data_get($json, 'usageMetadata.candidatesTokensDetails', []));
        $text = (int) $details->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'TEXT')->sum('tokenCount');
        $image = (int) $details->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'IMAGE')->sum('tokenCount');
        if ($details->isEmpty()) {
            // Without a breakdown the output is priced as image tokens, so a cleanup is never under-recorded.
            $image = (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0);
        }
        $cost = $this->costs->calculateBanner((int) data_get($json, 'usageMetadata.promptTokenCount', 0), $text, $image);
        $encoded = collect(data_get($json, 'candidates.0.content.parts', []))
            ->map(fn (array $part) => data_get($part, 'inlineData.data'))
            ->first(fn (mixed $data) => is_string($data));
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        $png = is_string($bytes) && $bytes !== '' ? $this->png($bytes) : null;
        if ($png === null) {
            // A filtered or malformed answer is still billed for its tokens, and is never cached.
            $message = 'Vertex AI returned no usable cleaned product reference image.';
            throw $cost > 0 ? new BannerAttemptFailed('vertex_missing_image', $message, round($cost, 6)) : new VertexAIResponseException('vertex_missing_image', $message, true);
        }

        return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)], 'cost' => $cost];
    }

    private function png(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
