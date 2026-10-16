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
 * magazine. Asked to draw the product into a scene, the image model redrew the stand as a paper book on
 * wood in eight rounds of pack 20. So the product is cut out once per photo instead: the image model
 * removes the props and sets the product on a flat key colour, the key is turned into transparency
 * here, and the cutout is checked against the store photo before it is kept. Banners then composite
 * this cutout onto a generated scene, so the product in an ad is the product in the photo.
 */
class BannerReferenceImage
{
    /** Bump when the cleanup prompt changes, so stored copies made by the old prompt are redone. */
    private const PROMPT_VERSION = 2;

    /** The lock outlives the cleanup call plus Google sign-in (OIDC, STS and IAM, up to 75s) before it. */
    private const LOCK_EXTRA_SECONDS = 120;

    /** A cutout covering less than this share of its image lost the product; more means the key failed. */
    private const MIN_COVERAGE = 0.02;

    private const MAX_COVERAGE = 0.92;

    public function __construct(private GoogleVertexAIClient $client, private ProviderCostCalculator $costs, private BannerImageInspector $inspector) {}

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
            return Cache::lock('banner-reference.'.$asset->id, $timeoutSeconds + 2 * (int) config('campaigns.banners.inspection_timeout_seconds') + self::LOCK_EXTRA_SECONDS)->block(max(1, $waitSeconds), function () use ($asset, $product, $timeoutSeconds, $key) {
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
        $photo = Storage::disk($asset->disk)->get($asset->path);
        [$keyName, $keyHex] = $this->keyColour($photo);
        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => "Product photo of {$product}. Show this exact product on its own, same angle, form, materials, proportions and the colour of every part, "
                    ."on a completely flat, evenly lit, pure {$keyName} ({$keyHex}) background that fills the whole image, with no shadow, reflection, floor or gradient. "
                    .'Remove everything that is not the product itself: books, magazines, devices, screens, plants, cups, hands and any other props, and fill in the product where they covered it. '
                    .'Show the whole product with a margin on every side. Keep marks printed on the product itself only. No other text, letters, logos or labels anywhere.'],
                ['inlineData' => ['mimeType' => $asset->mime_type, 'data' => base64_encode($photo)]],
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
        $png = is_string($bytes) && $bytes !== '' ? $this->cutout($bytes, $keyName) : null;
        if ($png === null) {
            // A filtered or malformed answer is still billed for its tokens, and is never cached.
            $message = 'Vertex AI returned no usable product cutout.';
            throw $cost > 0 ? new BannerAttemptFailed('vertex_missing_image', $message, round($cost, 6), is_string($bytes) && $bytes !== '' ? $bytes : null) : new VertexAIResponseException('vertex_missing_image', $message, true);
        }

        $cost += $this->check($png, $product, ['mimeType' => $asset->mime_type, 'data' => base64_encode($photo)], $cost);

        return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)], 'cost' => $cost];
    }

    /**
     * The cutout is every banner's product, so it is checked once, before it is kept: no third-party text
     * (a Kindle screen left in) and the same object, colours and proportions as the store photo.
     *
     * @param  array{mimeType: string, data: string}  $photo
     */
    private function check(string $png, string $product, array $photo, float $spent): float
    {
        if (! config('campaigns.banners.inspect_images') || ! config('campaigns.banners.check_product')) {
            return 0.0;
        }
        $model = (string) config('campaigns.google.model');
        $cost = 0.0;
        try {
            $marks = $this->inspector->inspect($png, 'image/png', $product, '');
            $cost += $this->costs->calculate($model, $marks['input_tokens'], 0, $marks['output_tokens']);
            if ($marks['marks'] !== []) {
                throw new BannerAttemptFailed('banner_reference_rejected', 'The product cutout showed third-party text or marks: '.mb_substr(implode(', ', $marks['marks']), 0, 300), round($spent + $cost, 6), $png);
            }
            $match = $this->inspector->matchesProduct($png, 'image/png', $product, $photo);
            $cost += $this->costs->calculate($model, $match['input_tokens'], 0, $match['output_tokens']);
        } catch (VertexAIResponseException $exception) {
            throw new BannerAttemptFailed($exception->errorCode, $exception->getMessage(), round($spent + $cost, 6));
        }
        if (! $match['matches']) {
            throw new BannerAttemptFailed('banner_product_mismatch', 'The product cutout did not match the store photo: '.mb_substr($match['differences'], 0, 300), round($spent + $cost, 6), $png);
        }

        return $cost;
    }

    /**
     * Green keys best, unless the product photo is itself green (a plant pot, a green case): then the
     * key is magenta, so no part of the product is keyed out.
     *
     * @return array{0: string, 1: string}
     */
    private function keyColour(string $photo): array
    {
        $image = @imagecreatefromstring($photo);
        if (! $image) {
            return ['green', '#00FF00'];
        }
        $small = imagescale($image, 64);
        imagedestroy($image);
        [$green, $magenta] = [0, 0];
        for ($y = 0; $y < imagesy($small); $y++) {
            for ($x = 0; $x < 64; $x++) {
                $c = imagecolorat($small, $x, $y);
                $green += self::keyStrength('green', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > 0.3 ? 1 : 0;
                $magenta += self::keyStrength('magenta', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > 0.3 ? 1 : 0;
            }
        }
        imagedestroy($small);

        return $green <= $magenta ? ['green', '#00FF00'] : ['magenta', '#FF00FF'];
    }

    /** How strongly a pixel is the key colour, 0 (not at all) to 1 (pure key). */
    private static function keyStrength(string $key, int $r, int $g, int $b): float
    {
        $excess = $key === 'green' ? $g - max($r, $b) : min($r, $b) - $g;

        return max(0.0, min(1.0, ($excess - 30) / 120));
    }

    /**
     * Turns the key colour into transparency, removes the key's tint from soft edges, and trims the
     * image to the product. Returns null when the result is not a usable cutout.
     */
    public function cutout(string $bytes, string $key = 'green'): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $out = imagecreatetruecolor($width, $height);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        [$left, $top, $right, $bottom, $opaque] = [$width, $height, -1, -1, 0];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($image, $x, $y);
                [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                $strength = self::keyStrength($key, $r, $g, $b);
                // Despill: the key colour reflected onto the product's edges is pulled back to neutral.
                if ($key === 'green') {
                    $g = min($g, max($r, $b));
                } elseif (($spill = min($r, $b) - $g) > 0) {
                    [$r, $b] = [$r - $spill, $b - $spill];
                }
                $alpha = (int) round($strength * 127);
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $alpha));
                if ($alpha < 64) {
                    $opaque++;
                    [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
                }
            }
        }
        imagedestroy($image);
        $coverage = $opaque / max(1, $width * $height);
        if ($coverage < self::MIN_COVERAGE || $coverage > self::MAX_COVERAGE) {
            imagedestroy($out);

            return null;
        }
        $trimmed = imagecrop($out, ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1]);
        imagedestroy($out);
        if (! $trimmed) {
            return null;
        }
        imagealphablending($trimmed, false);
        imagesavealpha($trimmed, true);
        ob_start();
        imagepng($trimmed);
        imagedestroy($trimmed);

        return (string) ob_get_clean();
    }
}
