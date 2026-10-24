<?php

namespace App\Http\Controllers;

use App\Models\ContentClientLink;
use App\Models\ContentItem;
use App\Models\ProductHubShare;
use Illuminate\Support\Facades\Storage;

/** Banner images a client may see through a client link or an older review link: the linked product only. */
class ClientReviewController extends Controller
{
    public function banner(string $token, ContentItem $contentItem)
    {
        $link = ContentClientLink::findActive($token);
        abort_unless($link, 404);

        return $this->image($link->product_id, $contentItem);
    }

    public function sharedBanner(string $token, ContentItem $contentItem)
    {
        $share = ProductHubShare::query()->where('token', $token)->first();
        abort_unless($share?->isActive(), 404);

        return $this->image($share->product_id, $contentItem);
    }

    private function image(int $productId, ContentItem $contentItem)
    {
        abort_unless($contentItem->product_id === $productId && in_array($contentItem->status, ['review', 'approved'], true), 404);
        $creative = $contentItem->bannerCreative;
        abort_unless($creative && $creative->status === 'completed' && $creative->output_path, 404);

        return Storage::disk($creative->disk)->response($creative->output_path, null, [
            'Content-Type' => $creative->output_mime_type ?: 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
