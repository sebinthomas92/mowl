<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetDownloadController extends Controller
{
    public function __invoke(Request $request, MediaAsset $mediaAsset): RedirectResponse|StreamedResponse
    {
        abort_unless($mediaAsset->workspace->users()->whereKey($request->user()->id)->exists(), 404);

        $disk = Storage::disk($mediaAsset->disk);
        abort_unless($disk->exists($mediaAsset->path), 404);

        if ($mediaAsset->disk === 'marketing-owl-media') {
            return redirect()->away($disk->temporaryUrl($mediaAsset->path, now()->addMinutes(5)));
        }

        return $disk->download($mediaAsset->path, $mediaAsset->original_name);
    }
}
