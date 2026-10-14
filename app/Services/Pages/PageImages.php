<?php

namespace App\Services\Pages;

use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/** Image references in page snapshots: where the app previews them and where their bytes live. */
class PageImages
{
    public function find(array $ref, int $workspaceId): BannerCreative|MediaAsset|null
    {
        if (($ref['kind'] ?? null) === 'banner') {
            $creative = BannerCreative::query()->with('campaignPack.product.brand')->find($ref['id'] ?? 0);

            return $creative && $creative->campaignPack?->product?->brand?->workspace_id === $workspaceId && $creative->output_path ? $creative : null;
        }
        if (($ref['kind'] ?? null) === 'media') {
            $asset = MediaAsset::query()->find($ref['id'] ?? 0);

            return $asset && $asset->workspace_id === $workspaceId ? $asset : null;
        }

        return null;
    }

    /** A URL the signed-in app can show. */
    public function previewUrl(array $ref, Product $product): ?string
    {
        $image = $this->find($ref, $product->brand->workspace_id);

        return match (true) {
            $image instanceof BannerCreative => route('campaign-banners.image', [$image->campaign_pack_id, $image]),
            $image instanceof MediaAsset => route('products.hub-media', [$image->product_id, $image]),
            default => null,
        };
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
