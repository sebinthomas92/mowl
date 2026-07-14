<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageImporter
{
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

        $lastException = null;
        foreach ($imageUrls as $rank => $imageUrl) {
            try {
                $image = $this->fetcher->fetchImage($imageUrl);
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
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException) {
            report($lastException);
        }

        return null;
    }

    private function originalName(string $url, string $extension): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return mb_substr($name !== '' ? rawurldecode($name) : "product-image.{$extension}", 0, 255);
    }
}
