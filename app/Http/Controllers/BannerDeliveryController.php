<?php

namespace App\Http\Controllers;

use App\Models\BannerCreative;
use App\Models\CampaignPack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BannerDeliveryController extends Controller
{
    public function image(Request $request, CampaignPack $pack, BannerCreative $bannerCreative): StreamedResponse
    {
        $this->authorizeCreative($request, $pack, $bannerCreative);

        return $this->response($bannerCreative, false);
    }

    public function download(Request $request, CampaignPack $pack, BannerCreative $bannerCreative): StreamedResponse
    {
        $this->authorizeCreative($request, $pack, $bannerCreative);
        $bannerCreative->batch->workspace->auditEvents()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'campaign_banner_downloaded',
            'subject_type' => BannerCreative::class,
            'subject_id' => $bannerCreative->id,
            'metadata' => ['campaign_pack_id' => $pack->id, 'version' => $bannerCreative->campaignPackVersion->version],
        ]);

        return $this->response($bannerCreative, true);
    }

    private function authorizeCreative(Request $request, CampaignPack $pack, BannerCreative $creative): void
    {
        abort_unless($creative->campaign_pack_id === $pack->id, 404);
        abort_unless($pack->product->brand->workspace->users()->whereKey($request->user()->id)->exists(), 404);
        abort_unless($creative->status === 'completed' && $creative->output_path, 404);
        abort_unless(Storage::disk($creative->disk)->exists($creative->output_path), 404);
    }

    private function response(BannerCreative $creative, bool $download): StreamedResponse
    {
        $stream = Storage::disk($creative->disk)->readStream($creative->output_path);
        abort_unless(is_resource($stream), 404);
        $filename = 'campaign-banner-'.$creative->campaign_pack_id.'-'.$creative->id.'.png';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
