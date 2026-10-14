<?php

namespace App\Http\Controllers;

use App\Models\ContentClientLink;
use App\Models\ContentItem;
use Illuminate\Support\Facades\Storage;

/** Files a client may see through their review link: banner images of the linked product only. */
class ClientReviewController extends Controller
{
    public function banner(string $token, ContentItem $contentItem)
    {
        $link = ContentClientLink::findActive($token);
        abort_unless($link && $contentItem->product_id === $link->product_id && in_array($contentItem->status, ['review', 'approved'], true), 404);
        $creative = $contentItem->bannerCreative;
        abort_unless($creative && $creative->status === 'completed' && $creative->output_path, 404);

        return Storage::disk($creative->disk)->response($creative->output_path, null, [
            'Content-Type' => $creative->output_mime_type ?: 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
