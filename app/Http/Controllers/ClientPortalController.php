<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Support\Membership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Banner images in the client portal: only items in review or approved, only within the viewer's brand scope. */
class ClientPortalController extends Controller
{
    public function image(Request $request, ContentItem $contentItem)
    {
        $membership = Membership::of($request->user(), $contentItem->workspace_id);
        abort_unless($membership && $membership->allowsBrand($contentItem->product?->brand_id), 404);
        abort_unless(in_array($contentItem->status, ['review', 'approved'], true), 404);
        $creative = $contentItem->bannerCreative;
        abort_unless($creative && $creative->status === 'completed' && $creative->output_path, 404);
        abort_unless(Storage::disk($creative->disk)->exists($creative->output_path), 404);

        $headers = ['Content-Type' => $creative->output_mime_type ?: 'image/png', 'Cache-Control' => 'private, max-age=300'];
        if ($request->boolean('download')) {
            $extension = pathinfo($creative->output_path, PATHINFO_EXTENSION) ?: 'png';

            return Storage::disk($creative->disk)->download($creative->output_path, Str::slug($contentItem->title).'-v'.$contentItem->current_version.'.'.$extension, $headers);
        }

        return Storage::disk($creative->disk)->response($creative->output_path, null, $headers);
    }
}
