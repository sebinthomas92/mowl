<?php

namespace App\Services\Pages;

use App\Models\MediaAsset;
use App\Models\ProcessingCacheEntry;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\ProductImageImporter;
use App\Services\ProductPageFetcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Images for pages: the product's own photos from its store page, and images people upload. */
class ProductPhotos
{
    public const MAX_UPLOAD_KB = 10240;

    public function __construct(private ProductImageImporter $importer, private ProductPageFetcher $fetcher) {}

    /**
     * Brings in the store's gallery photos of the product, so a page can show more than the one photo
     * imported for banners. A page that now shows another product (a reused address) adds nothing.
     *
     * @return int photos added
     */
    public function importFromProductPage(Product $product): int
    {
        $source = $product->sourceSnapshots()->where('status', 'ready')->latest('id')->first();
        if (! $source instanceof SourceSnapshot || ! $source->url) {
            throw ValidationException::withMessages(['images' => 'This product has no product page to take photos from. Add its page address on the Overview tab.']);
        }
        $gallery = $source->content_hash
            ? (array) (ProcessingCacheEntry::query()->where('cache_key', hash('sha256', 'source-extraction:v1:'.$source->content_hash))->value('payload')['gallery_images'] ?? [])
            : [];
        if ($gallery === []) {
            try {
                $fetched = $this->fetcher->fetch($source->canonical_url ?: $source->url);
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['images' => 'The product page could not be read just now. Try again, or upload photos instead.']);
            }
            $then = fn (string $field) => mb_strtolower(trim((string) ($source->extracted_truth[$field] ?? '')));
            $now = fn (string $field) => mb_strtolower(trim((string) ($fetched['product_truth'][$field] ?? '')));
            $field = $then('sku') !== '' && $now('sku') !== '' ? 'sku' : 'name';
            if ($then($field) !== '' && $now($field) !== '' && $then($field) !== $now($field)) {
                throw ValidationException::withMessages(['images' => 'The product page now shows a different product, so no photos were taken from it.']);
            }
            $gallery = array_values(array_unique(array_merge($fetched['gallery_images'] ?? [], $fetched['images'] ?? [])));
        }
        if ($gallery === []) {
            throw ValidationException::withMessages(['images' => 'No product photos were found on the product page. Upload photos instead.']);
        }

        return $this->importer->importGallery($product, $source, array_slice($gallery, 0, 16));
    }

    /** An image a person uploaded for the product's pages. */
    public function storeUpload(Product $product, UploadedFile $file): MediaAsset
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($bytes);
        $mime = $size['mime'] ?? '';
        if (! $size || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw ValidationException::withMessages(['upload' => 'Upload a JPG, PNG, WebP or GIF image.']);
        }
        if ($size[0] * $size[1] > ProductImageImporter::MAX_PIXELS) {
            throw ValidationException::withMessages(['upload' => 'This image is too large. Use one under 12 megapixels.']);
        }
        $product->loadMissing('brand');
        $hash = hash('sha256', $bytes);
        $extension = ['image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'png';
        $disk = config('campaigns.banners.disk');
        $path = "campaign-media/{$product->brand->workspace_id}/{$product->id}/uploads/{$hash}.{$extension}";
        Storage::disk($disk)->put($path, $bytes);

        return $product->mediaAssets()->firstOrCreate(['content_hash' => $hash], [
            'workspace_id' => $product->brand->workspace_id, 'type' => 'image', 'disk' => $disk, 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: "upload.{$extension}", 0, 255), 'mime_type' => $mime,
            'size_bytes' => strlen($bytes), 'status' => 'ready', 'processed_at' => now(),
            'metadata' => ['origin' => 'page_upload', 'width' => $size[0], 'height' => $size[1]],
        ]);
    }
}
