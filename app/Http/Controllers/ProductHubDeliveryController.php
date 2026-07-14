<?php

namespace App\Http\Controllers;

use App\Models\BannerCreative;
use App\Models\CampaignPackVersion;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductHubShare;
use App\Services\ProductHubContentAdapter;
use App\Services\ProductHubCsvExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductHubDeliveryController extends Controller
{
    public function share(string $token, ProductHubContentAdapter $adapter): Response
    {
        $share = $this->activeShare($token);
        $product = $share->product->load(['brand', 'resourceLinks', 'mediaAssets', 'campaignPacks.versions']);
        $version = $this->approvedVersion($product);
        $content = $adapter->adapt($product, $version);

        return response()->view('product-hubs.share', compact('share', 'product', 'version', 'content'));
    }

    public function export(Request $request, Product $product, string $campaignType, ProductHubContentAdapter $adapter, ProductHubCsvExporter $exporter): Response
    {
        $this->authorizeProduct($request, $product);

        return $this->csvResponse($product, $campaignType, $adapter, $exporter);
    }

    public function sharedExport(string $token, string $campaignType, ProductHubContentAdapter $adapter, ProductHubCsvExporter $exporter): Response
    {
        $product = $this->activeShare($token)->product;

        return $this->csvResponse($product, $campaignType, $adapter, $exporter);
    }

    public function media(Request $request, Product $product, MediaAsset $mediaAsset): StreamedResponse
    {
        $this->authorizeProduct($request, $product);

        return $this->mediaResponse($product, $mediaAsset);
    }

    public function sharedMedia(string $token, MediaAsset $mediaAsset): StreamedResponse
    {
        return $this->mediaResponse($this->activeShare($token)->product, $mediaAsset);
    }

    public function creative(Request $request, Product $product, BannerCreative $bannerCreative): StreamedResponse
    {
        $this->authorizeProduct($request, $product);

        return $this->creativeResponse($product, $bannerCreative);
    }

    public function sharedCreative(string $token, BannerCreative $bannerCreative): StreamedResponse
    {
        return $this->creativeResponse($this->activeShare($token)->product, $bannerCreative);
    }

    private function csvResponse(Product $product, string $campaignType, ProductHubContentAdapter $adapter, ProductHubCsvExporter $exporter): Response
    {
        $version = $this->approvedVersion($product);
        $type = $exporter->normalizeType($campaignType);
        $csv = $exporter->export($adapter->adapt($product, $version), $type);
        $filename = str($product->name)->slug()."-google-ads-{$type}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function approvedVersion(Product $product): CampaignPackVersion
    {
        return CampaignPackVersion::query()
            ->whereHas('campaignPack', fn ($query) => $query->where('product_id', $product->id))
            ->where('review_status', 'approved')->with(['campaignPack.sourceSnapshot', 'bannerCreatives'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')->firstOrFail();
    }

    private function activeShare(string $token): ProductHubShare
    {
        return ProductHubShare::query()->with('product.brand')->where('token', $token)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        abort_unless($product->brand->workspace->users()->whereKey($request->user()->id)->exists(), 404);
    }

    private function mediaResponse(Product $product, MediaAsset $mediaAsset): StreamedResponse
    {
        abort_unless($mediaAsset->product_id === $product->id && $mediaAsset->status !== 'failed', 404);
        abort_unless(Storage::disk($mediaAsset->disk)->exists($mediaAsset->path), 404);
        $stream = Storage::disk($mediaAsset->disk)->readStream($mediaAsset->path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mediaAsset->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($mediaAsset->original_name).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function creativeResponse(Product $product, BannerCreative $creative): StreamedResponse
    {
        abort_unless($creative->campaignPack?->product_id === $product->id, 404);
        abort_unless($creative->campaignPackVersion?->review_status === 'approved', 404);
        abort_unless($creative->status === 'completed' && $creative->output_path, 404);
        abort_unless(Storage::disk($creative->disk)->exists($creative->output_path), 404);
        $stream = Storage::disk($creative->disk)->readStream($creative->output_path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="campaign-creative-'.$creative->id.'.png"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
