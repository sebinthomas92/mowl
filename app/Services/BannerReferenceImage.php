<?php

namespace App\Services;

use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\MediaAsset;
use App\Models\SourceSnapshot;
use App\Support\GenerationDeadline;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
    /** Bump when the cleanup prompt or the cutout changes, so stored copies made the old way are redone. */
    private const PROMPT_VERSION = 4;

    /** Store photos downloaded while looking for a studio shot to cut out. */
    private const MAX_GALLERY_PHOTOS = 6;

    /** @var array{0: string, 1: string}|null The store's main product photo (type, bytes) from the last gallery search. */
    private ?array $mainPhoto = null;

    /** The lock outlives the cleanup call plus Google sign-in (OIDC, STS and IAM, up to 75s) before it. */
    private const LOCK_EXTRA_SECONDS = 120;

    /** A cutout covering less than this share of its image lost the product; more means the key failed. */
    private const MIN_COVERAGE = 0.02;

    private const MAX_COVERAGE = 0.92;

    /** A shadow, floor or gradient left around the product shows as an opaque border; most of it must be key. */
    private const MIN_KEYED_BORDER = 0.9;

    /** A small compressed file can decode to hundreds of MB; photos and cutouts are far below this. */
    public function __construct(
        private GoogleVertexAIClient $client,
        private ProviderCostCalculator $costs,
        private BannerImageInspector $inspector,
        private ProductPhotoCutout $cutter,
        private ProductPageFetcher $fetcher,
    ) {}

    /**
     * Banners for one pack run side by side, so the copy is made under a per-photo lock: the others
     * wait up to $waitSeconds and reuse it instead of paying for their own (possibly different) copy.
     *
     * @return array{inlineData: array{mimeType: string, data: string}, cost: float}
     */
    public function for(MediaAsset $asset, string $product, int $timeoutSeconds, int $waitSeconds, string $brand = '', ?SourceSnapshot $source = null): array
    {
        // The pack's own source wins: a product refreshed since the photo was imported keeps that older
        // asset, and its gallery and approval are the pack's, not the import's.
        $source ??= $asset->sourceSnapshot;
        if (! $asset->exists) {
            return $this->make($asset, $product, $timeoutSeconds, $brand, $source);
        }
        // A cutout can come from the page's gallery, so a refreshed (and re-approved) page makes a new one.
        $key = self::key($product, $brand, $source ? $source->content_hash.'|'.$source->approved_at?->toIso8601String() : '');
        if ($cached = $this->cached($asset, $key)) {
            return $cached;
        }

        try {
            return Cache::lock('banner-reference.'.$asset->id, $timeoutSeconds + 2 * (int) config('campaigns.banners.inspection_timeout_seconds') + self::LOCK_EXTRA_SECONDS)->block(max(1, $waitSeconds), function () use ($asset, $product, $timeoutSeconds, $key, $brand, $source) {
                if ($cached = $this->cached($asset->refresh(), $key)) {
                    return $cached;
                }
                $clean = $this->make($asset, $product, $timeoutSeconds, $brand, $source);
                $path = dirname($asset->path).'/banner-reference-'.$asset->id.'.png';
                // The copy is already paid for: a failed write (false, or a throw on the gcs disk) only
                // means it is used once and not recorded.
                try {
                    if (Storage::disk($asset->disk)->put($path, base64_decode($clean['inlineData']['data'])) !== false) {
                        $asset->forceFill(['derivatives' => [
                            ...($asset->derivatives ?? []),
                            'banner_reference' => $path,
                            'banner_reference_key' => $key,
                            'banner_reference_source' => $clean['source'] ?? null,
                        ]])->save();
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

    /**
     * The copy depends on the product name in the prompt, and its marks check exempts the brand's own
     * mark, so a renamed product or brand gets a new one.
     */
    public static function key(string $product, string $brand = '', string $source = ''): string
    {
        // Which checks the cutout passed is part of the key, so turning a check on remakes cutouts made without it.
        $checks = (int) (bool) config('campaigns.banners.inspect_images').(int) (bool) config('campaigns.banners.check_product');

        return substr(sha1(self::PROMPT_VERSION."\n".$product."\n".$brand."\n".$checks.($source !== '' ? "\n".$source : '')), 0, 16);
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

    /**
     * A studio shot of the product on a plain backdrop is cut out pixel for pixel, with no AI redraw.
     * Only when the store has none is the image model asked to lift the product out of a styled photo.
     *
     * @return array{inlineData: array{mimeType: string, data: string}, cost: float, source?: string}
     */
    private function make(MediaAsset $asset, string $product, int $timeoutSeconds, string $brand, ?SourceSnapshot $source): array
    {
        $photo = Storage::disk($asset->disk)->get($asset->path);
        $spent = 0.0;
        $this->mainPhoto = null;
        foreach ($this->studioCutouts($asset, $photo, $source) as $studio) {
            try {
                $spent += $this->check($studio['png'], $product, $brand, $studio['reference'], $spent, studio: true);
            } catch (BannerAttemptFailed $exception) {
                // A studio shot with readable text (a device screen in the stand), other objects or another
                // variant of the product is skipped for the next one.
                if (! in_array($exception->errorCode, ['banner_reference_rejected', 'banner_product_mismatch'], true)) {
                    throw $exception;
                }
                $spent = $exception->spentCost;

                continue;
            }

            return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($studio['png'])], 'cost' => $spent, 'source' => $studio['source']];
        }

        try {
            // The store's main product photo, when it was downloaded and is not the import, is the truer
            // picture to redraw from: the import can be a share image of another variant.
            [$mime, $bytes] = $this->mainPhoto ?? [(string) $asset->mime_type, $photo];
            $redrawn = $this->redraw($mime, $bytes, $product, $timeoutSeconds, $brand, $spent);
        } catch (VertexAIResponseException $exception) {
            // The studio photos' paid checks stay on the banner's cost when the redraw call fails.
            throw $spent > 0 ? new BannerAttemptFailed($exception->errorCode, $exception->getMessage(), round($spent, 6)) : $exception;
        }

        return [...$redrawn, 'cost' => $redrawn['cost'] + $spent];
    }

    /**
     * The imported photo first, then the product's own gallery photos from its page (a share image is
     * often a styled scene, sometimes of another variant). A gallery can also show other variants, so a
     * photo other than the store's main product photo (the gallery's first) is compared with it,
     * the imported photo included. A trusted photo's cutout is compared with the photo it was cut
     * from, so a part the cutter took for backdrop or shadow is caught before the cutout is used.
     *
     * @return \Generator<int, array{png: string, source: string, reference: array{mimeType: string, data: string}|null}>
     */
    private function studioCutouts(MediaAsset $asset, string $photo, ?SourceSnapshot $snapshot): \Generator
    {
        $source = (string) data_get($asset->metadata, 'source_url');
        $imported = $this->cutter->cut($photo);
        $page = $snapshot?->url;
        // An uploaded photo is the product by the owner's word. An imported one is trusted unchecked only
        // when it is the gallery's main photo: older imports took the page's share image first.
        $gallery = [];
        if ($page) {
            try {
                $fetched = $this->fetcher->fetch($page);
            } catch (Throwable $exception) {
                // Without the page there is no telling whether the import is the main photo: the redraw,
                // which is checked against the imported photo as a whole, is used instead.
                report($exception);

                return;
            }
            // The owner approved the product the page showed then. A page that now names another
            // product (a reused URL) is not that product's gallery. The SKU decides when both have one,
            // so a retitled page of the same product still counts.
            $approved = (array) ($snapshot->extracted_truth ?? []);
            $then = fn (string $field) => mb_strtolower(trim((string) ($approved[$field] ?? '')));
            $now = fn (string $field) => mb_strtolower(trim((string) ($fetched['product_truth'][$field] ?? '')));
            $field = $then('sku') !== '' && $now('sku') !== '' ? 'sku' : 'name';
            if ($then($field) !== '' && $now($field) !== '' && $then($field) !== $now($field)) {
                return;
            }
            $gallery = $fetched['gallery_images'] ?? [];
        }
        $own = ['mimeType' => (string) $asset->mime_type, 'data' => base64_encode($photo)];
        // With no gallery to compare with, the imported photo is the reference the redraw would use too.
        if ($imported && ($gallery === [] || $gallery[0] === $source)) {
            yield ['png' => $imported, 'source' => $source ?: $asset->path, 'reference' => $own];
            $imported = null;
        }
        if ($gallery === []) {
            return;
        }
        $main = $gallery[0] === $source ? $own : null;
        foreach (array_slice(array_values(array_diff($gallery, [$source])), 0, self::MAX_GALLERY_PHOTOS) as $url) {
            // Only the gallery's first photo is trusted as the product: when it cannot be downloaded, a
            // later one (possibly another variant) has nothing to be compared with.
            if ($main === null && $url !== $gallery[0]) {
                break;
            }
            // A download is only started while it and both of the cutout's checks (marks, then the product
            // match) still fit the deadline. The scene is not reserved here: an accepted cutout is stored,
            // and a banner short of time for its scene retries straight from it.
            if (! $this->hasTime((int) config('campaigns.source.timeout_seconds') + (int) config('campaigns.banners.inspection_timeout_seconds'))) {
                break;
            }
            try {
                $image = $this->fetcher->fetchImage($url);
            } catch (Throwable) {
                continue;
            }
            // A photo too large to decode within memory cannot be shrunk for the inspector either, so it
            // is neither cut out nor used as the main photo (the cutter skips it too).
            $usable = $image['width'] * $image['height'] <= ProductImageImporter::MAX_PIXELS;
            $own = ['mimeType' => $image['mime_type'], 'data' => base64_encode($image['bytes'])];
            $reference = $main;
            if ($main === null && $usable) {
                $main = $own;
                $this->mainPhoto = [$image['mime_type'], $image['bytes']];
            }
            if ($main === null) {
                break;
            }
            if ($imported) {
                yield ['png' => $imported, 'source' => $source ?: $asset->path, 'reference' => $main];
                $imported = null;
            }
            if ($usable && $png = $this->cutter->cut($image['bytes'])) {
                yield ['png' => $png, 'source' => $image['url'], 'reference' => $reference ?? $own];
            }
        }
    }

    /** @return array{inlineData: array{mimeType: string, data: string}, cost: float} */
    private function redraw(string $mimeType, string $photo, string $product, int $timeoutSeconds, string $brand, float $earlier): array
    {
        [$keyName, $keyHex] = $this->keyColour($photo);
        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => "Product photo of {$product}. Show this exact product on its own, same angle, form, materials, proportions and the colour of every part, "
                    ."on a completely flat, evenly lit, pure {$keyName} ({$keyHex}) background that fills the whole image, with no shadow, reflection, floor or gradient. "
                    .'Remove everything that is not the product itself: books, magazines, devices, screens, plants, cups, hands and any other props, and fill in the product where they covered it. '
                    .'Show the whole product with a margin on every side. Keep marks printed on the product itself only. No other text, letters, logos or labels anywhere.'],
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($photo)]],
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
            throw $cost + $earlier > 0 ? new BannerAttemptFailed('vertex_missing_image', $message, round($earlier + $cost, 6), is_string($bytes) && $bytes !== '' ? $bytes : null) : new VertexAIResponseException('vertex_missing_image', $message, true);
        }

        $cost += $this->check($png, $product, $brand, ['mimeType' => $mimeType, 'data' => base64_encode($photo)], $earlier + $cost);

        return ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)], 'cost' => $cost];
    }

    /**
     * The cutout is every banner's product, so it is checked once, before it is kept: no third-party text
     * (a Kindle screen left in) and the same object, colours and proportions as the store photo.
     *
     * A studio cutout is the photo's own pixels: it is also asked for other objects, and compared only
     * when it comes from a later gallery photo ($photo is then the store's main product photo).
     *
     * @param  array{mimeType: string, data: string}|null  $photo
     */
    private function check(string $png, string $product, string $brand, ?array $photo, float $spent, bool $studio = false): float
    {
        if (! config('campaigns.banners.inspect_images')) {
            return 0.0;
        }
        $model = (string) config('campaigns.google.model');
        $cost = 0.0;
        try {
            $this->ensureTime();
            $marks = $this->retried(fn () => $this->inspector->inspect($png, 'image/png', $product, $brand, cutout: $studio));
            $cost += $this->costs->calculate($model, $marks['input_tokens'], 0, $marks['output_tokens']);
            if ($studio && $marks['other_objects'] !== []) {
                // A studio shot with a vase or candle beside the product, joined to it in the cutout.
                throw new BannerAttemptFailed('banner_reference_rejected', 'The product photo showed other objects: '.mb_substr(implode(', ', $marks['other_objects']), 0, 300), round($spent + $cost, 6), $png);
            }
            if ($marks['marks'] !== []) {
                throw new BannerAttemptFailed('banner_reference_rejected', 'The product cutout showed third-party text or marks: '.mb_substr(implode(', ', $marks['marks']), 0, 300), round($spent + $cost, 6), $png);
            }
            $compare = $photo !== null && config('campaigns.banners.check_product');
            if ($compare) {
                $this->ensureTime();
            }
            $match = $compare ? $this->retried(fn () => $this->inspector->matchesProduct($png, 'image/png', $product, $photo)) : null;
            $cost += $match ? $this->costs->calculate($model, $match['input_tokens'], 0, $match['output_tokens']) : 0.0;
        } catch (VertexAIResponseException $exception) {
            throw new BannerAttemptFailed($exception->errorCode, $exception->getMessage(), round($spent + $cost, 6));
        }
        if ($match && ! $match['matches']) {
            throw new BannerAttemptFailed('banner_product_mismatch', 'The product cutout did not match the store photo: '.mb_substr($match['differences'], 0, 300), round($spent + $cost, 6), $png);
        }

        return $cost;
    }

    /**
     * A check that was rate limited or hung is tried once more, so the paid-for cutout is not thrown
     * away, but only while the run's deadline still has room for it.
     */
    private function retried(callable $call): array
    {
        try {
            return $call();
        } catch (VertexAIResponseException $exception) {
            if (! in_array($exception->errorCode, ['vertex_http_429', 'vertex_connection_error'], true) || ! $this->hasTime(10)) {
                throw $exception;
            }
            Sleep::for(10)->seconds();

            return $call();
        }
    }

    /** Whether a full inspection, after $pause seconds, still fits the run's deadline (none: always). */
    private function hasTime(int $pause = 0): bool
    {
        $remaining = app(GenerationDeadline::class)->remaining();

        return $remaining === null || $remaining >= $pause + (int) config('campaigns.banners.inspection_timeout_seconds') + 5;
    }

    private function ensureTime(): void
    {
        if (! $this->hasTime()) {
            throw new VertexAIResponseException('banner_deadline_in_reference_check', 'Not enough time was left to check the product cutout.', true);
        }
    }

    private static function decode(string $bytes): ?\GdImage
    {
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > ProductImageImporter::MAX_PIXELS) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);

        return $image ?: null;
    }

    /**
     * Green keys best, unless the product photo is itself green (a plant pot, a green case): then the
     * key is magenta, so no part of the product is keyed out.
     *
     * @return array{0: string, 1: string}
     */
    private function keyColour(string $photo): array
    {
        $image = self::decode($photo);
        if (! $image) {
            return ['green', '#00FF00'];
        }
        imagepalettetotruecolor($image);
        // A sample at most 64px on its longer side; never upscaled, so a very narrow photo stays small.
        $factor = min(1, 64 / max(imagesx($image), imagesy($image)));
        $small = imagescale($image, max(1, (int) round(imagesx($image) * $factor)), max(1, (int) round(imagesy($image) * $factor)));
        imagedestroy($image);
        if (! $small) {
            return ['green', '#00FF00'];
        }
        [$green, $magenta] = [0, 0];
        for ($y = 0; $y < imagesy($small); $y++) {
            for ($x = 0; $x < imagesx($small); $x++) {
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
        $image = self::decode($bytes);
        if (! $image) {
            return null;
        }
        // imagecolorat() returns palette indexes, not colours, for an indexed PNG.
        imagepalettetotruecolor($image);
        $width = imagesx($image);
        $height = imagesy($image);
        $out = imagecreatetruecolor($width, $height);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        [$left, $top, $right, $bottom, $opaque, $border, $keyedBorder] = [$width, $height, -1, -1, 0, 0, 0];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($image, $x, $y);
                [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                $strength = self::keyStrength($key, $r, $g, $b);
                // Despill: the key colour reflected onto the product's edges is pulled back to neutral. Only
                // pixels partly classed as key are touched, so the product's own greens or purples stay exact.
                if ($strength > 0.0 && $key === 'green') {
                    $g = min($g, max($r, $b));
                } elseif ($strength > 0.0 && ($spill = min($r, $b) - $g) > 0) {
                    [$r, $b] = [$r - $spill, $b - $spill];
                }
                $alpha = (int) round($strength * 127);
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $alpha));
                if ($x === 0 || $y === 0 || $x === $width - 1 || $y === $height - 1) {
                    $border++;
                    $keyedBorder += $alpha >= 64 ? 1 : 0;
                }
                if ($alpha < 64) {
                    $opaque++;
                    [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
                }
            }
        }
        imagedestroy($image);
        $coverage = $opaque / max(1, $width * $height);
        if ($coverage < self::MIN_COVERAGE || $coverage > self::MAX_COVERAGE || $keyedBorder < $border * self::MIN_KEYED_BORDER) {
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
