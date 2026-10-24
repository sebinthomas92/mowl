<?php

namespace App\Services\Pages;

use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Services\Pages\Document\ProductBrief;
use Illuminate\Support\Facades\Storage;

/**
 * Image references in page snapshots: where the app previews them and where their bytes live.
 * A page uses its own product's images only; whether a banner may be published is decided by
 * {@see ProductBrief::images()}.
 */
class PageImages
{
    /** The image in the workspace, and of the product when $productId is given. */
    public function find(array $ref, int $workspaceId, ?int $productId = null): BannerCreative|MediaAsset|null
    {
        if (($ref['kind'] ?? null) === 'banner') {
            $creative = BannerCreative::query()->with('campaignPack.product.brand')->find($ref['id'] ?? 0);
            $product = $creative?->campaignPack?->product;

            return $creative && $product?->brand?->workspace_id === $workspaceId && ($productId === null || $product->id === $productId) && $creative->output_path ? $creative : null;
        }
        if (($ref['kind'] ?? null) === 'media') {
            $asset = MediaAsset::query()->find($ref['id'] ?? 0);

            return $asset && $asset->workspace_id === $workspaceId && ($productId === null || (int) $asset->product_id === $productId) ? $asset : null;
        }

        return null;
    }

    /** A URL the signed-in app can show. */
    public function previewUrl(array $ref, Product $product): ?string
    {
        $image = $this->find($ref, $product->brand->workspace_id, $product->id);

        return match (true) {
            $image instanceof BannerCreative => route('campaign-banners.image', [$image->campaign_pack_id, $image]),
            $image instanceof MediaAsset => route('products.hub-media', [$image->product_id, $image]),
            default => null,
        };
    }

    /**
     * The image's size in pixels, when the app knows it.
     *
     * @return array{width: ?int, height: ?int}
     */
    public function dimensions(array $ref, int $workspaceId): array
    {
        $image = $this->find($ref, $workspaceId);
        [$width, $height] = match (true) {
            $image instanceof BannerCreative => [$image->width, $image->height],
            $image instanceof MediaAsset => [$image->metadata['width'] ?? null, $image->metadata['height'] ?? null],
            default => [null, null],
        };

        return ['width' => (int) $width > 0 ? (int) $width : null, 'height' => (int) $height > 0 ? (int) $height : null];
    }

    /** @return array{bytes: string, mime: string, filename: string}|null */
    public function contents(array $ref, int $workspaceId): ?array
    {
        $image = $this->find($ref, $workspaceId);
        if (! $image) {
            return null;
        }
        [$disk, $path, $mime] = $image instanceof BannerCreative
            ? [$image->disk, $image->output_path, $image->output_mime_type ?: 'image/png']
            : [$image->disk, $image->path, $image->mime_type];
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }
        $extension = ['image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? 'png';

        return ['bytes' => Storage::disk($disk)->get($path), 'mime' => $mime, 'filename' => "marketingowl-{$ref['key']}.{$extension}"];
    }
}
