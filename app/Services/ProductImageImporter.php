<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageImporter
{
    /** Longest side, in pixels, below which a photo is a thumbnail. */
    public const MIN_SIDE = 500;

    /**
     * Largest photo decoded later (cutout, banner reference) within the 128 MB memory limit: GD holds
     * 4 bytes a pixel, and a well-compressed huge PNG can still be a small download.
     */
    public const MAX_PIXELS = 12_000_000;

    private const MORE_AFTER_THUMBNAIL = 3;

    public function __construct(private ProductPageFetcher $fetcher) {}

    public function importFirst(Product $product, SourceSnapshot $source, array $imageUrls): ?MediaAsset
    {
        $existing = $product->mediaAssets()
            ->where('type', 'image')
            ->where('metadata->origin', 'product_page')
            ->oldest()
            ->first();
        if ($existing) {
            return $existing;
        }

        // A thumbnail (structured data sometimes lists one first) is only used when no full-size photo
        // can be downloaded: the banner enlarges the product to around 450 px.
        $small = null;
        $lastException = null;
        foreach (array_values($imageUrls) as $rank => $imageUrl) {
            // With a usable thumbnail in hand, only a few more candidates are tried: each download can
            // take the full fetch timeout, and the pack's generation has not started yet.
            if ($small && $rank - $small[0] > self::MORE_AFTER_THUMBNAIL) {
                break;
            }
            try {
                $image = $this->fetcher->fetchImage($imageUrl);
                if ($image['width'] * $image['height'] > self::MAX_PIXELS) {
                    continue;
                }
                if (max($image['width'], $image['height']) < self::MIN_SIDE) {
                    $small ??= [$rank, $image];

                    continue;
                }

                return $this->store($product, $source, $rank, $image);
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($small) {
            try {
                return $this->store($product, $source, ...$small);
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException) {
            report($lastException);
        }

        return null;
    }

    /** @param  array{bytes: string, mime_type: string, url: string, width: int, height: int}  $image */
    private function store(Product $product, SourceSnapshot $source, int $rank, array $image): MediaAsset
    {
        $hash = hash('sha256', $image['bytes']);
        $extension = match ($image['mime_type']) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $disk = config('campaigns.banners.disk');
        $path = "campaign-media/{$product->brand->workspace_id}/{$product->id}/source/{$hash}.{$extension}";
        Storage::disk($disk)->put($path, $image['bytes']);

        return $product->mediaAssets()->firstOrCreate(
            ['content_hash' => $hash],
            [
                'workspace_id' => $product->brand->workspace_id,
                'source_snapshot_id' => $source->id,
                'type' => 'image',
                'disk' => $disk,
                'path' => $path,
                'original_name' => $this->originalName($image['url'], $extension),
                'mime_type' => $image['mime_type'],
                'size_bytes' => strlen($image['bytes']),
                'status' => 'ready',
                'metadata' => [
                    'origin' => 'product_page',
                    'source_url' => $image['url'],
                    'candidate_rank' => $rank + 1,
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'auto_imported' => true,
                ],
            ],
        );
    }

    private function originalName(string $url, string $extension): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return mb_substr($name !== '' ? rawurldecode($name) : "product-image.{$extension}", 0, 255);
    }
}
