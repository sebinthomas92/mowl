<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Product;
use App\Services\Pages\Document\DocumentRenderer;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Document\Templates;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PageImages;
use App\Services\Pages\PageRenderer;
use App\Support\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Signed-in previews: a page draft as it will publish, and each template filled from the product. */
class PagePreviewController extends Controller
{
    /** Previews show product text from outside sources and never need scripts, so none may run. */
    private const POLICY = "script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    public function draft(Request $request, Product $product, Page $page, PageBuilder $builder, PageRenderer $renderer, PageImages $images): Response
    {
        $membership = $this->member($request, $product);
        abort_unless(PageBuilder::available() && $page->product_id === $product->id, 404);
        $html = $renderer->page($builder->snapshot($page), $page->draft_version, fn (array $ref) => $images->previewUrl($ref, $product));
        [$back, $backLabel] = $membership->canCreate()
            ? [route('pages.edit', [$product, $page]), 'Back to the editor']
            : [route('products.show', [$product, 'tab' => 'pages']), 'Back to the product'];

        return response()
            ->view('pages.preview', [
                // Thumbnails in the Pages tab show the page alone.
                'title' => $page->name, 'html' => $html, 'bar' => ! $request->boolean('thumb'), 'back' => $back, 'backLabel' => $backLabel,
                'label' => "Draft v{$page->draft_version} of {$page->name}. Only your team can see this preview; the store shows the last published version.",
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', self::POLICY)
            ->header('Cache-Control', 'private, no-store');
    }

    /** A template filled from what the product's sources say, for the template gallery. */
    public function template(Request $request, Product $product, string $template, DocumentRenderer $renderer, PageImages $images): Response
    {
        $this->member($request, $product);
        abort_unless(PageBuilder::available() && Templates::exists($template), 404);
        $product->loadMissing('brand');
        $document = Templates::document($template, ProductBrief::for($product));
        $html = $renderer->render($document, ['page_id' => 0], fn (array $ref) => $images->previewUrl($ref, $product));

        return response()
            ->view('pages.preview', ['title' => $product->name, 'html' => $html, 'bar' => false, 'back' => null, 'backLabel' => null, 'label' => null])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', self::POLICY)
            ->header('Cache-Control', 'private, max-age=300');
    }

    /** The role middleware applies brand scopes; this also turns away people outside the product's workspace. */
    private function member(Request $request, Product $product): Membership
    {
        $product->loadMissing('brand');
        $membership = Membership::of($request->user(), $product->brand?->workspace_id);
        abort_unless($membership && ! $membership->isClient() && $membership->allowsBrand($product->brand_id), 404);

        return $membership;
    }
}
