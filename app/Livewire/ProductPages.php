<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Page;
use App\Models\Product;
use App\Services\Pages\Document\Templates;
use App\Services\Pages\PageBuilder;
use App\Support\Membership;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The product's Pages tab: its landing pages, and the template gallery a new page starts from. Pages are
 * edited in the full-screen editor ({@see PageEditor}) and published from there.
 */
class ProductPages extends Component
{
    use InteractsWithWorkspace;

    #[Locked]
    public int $productId;

    /** A page a link points at (notifications, the product hero), shown first. */
    #[Url(as: 'page')]
    public ?int $pageId = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        abort_unless($this->product(), 404);
    }

    /** A new page from a template. With $ai the editor writes its copy as soon as it opens. */
    public function create(string $template, bool $ai, PageBuilder $builder)
    {
        $product = $this->product();
        abort_unless($product && $this->canEdit(), 403);
        abort_unless(PageBuilder::available() && Templates::exists($template), 404);
        $count = Page::query()->where('product_id', $product->id)->count();
        $page = $builder->start($product, auth()->user(), $count === 0 ? null : 'Page '.($count + 1), $template, $ai);

        return $this->redirectRoute('pages.edit', ['product' => $product, 'page' => $page] + ($ai ? ['write' => 1] : []));
    }

    public function render()
    {
        $product = $this->product();
        abort_unless($product, 404);
        $available = PageBuilder::available();
        $pages = $available ? Page::query()->where('product_id', $product->id)->latest('updated_at')->latest('id')->get() : collect();
        if ($this->pageId && ($target = $pages->firstWhere('id', $this->pageId))) {
            $pages = $pages->reject(fn (Page $page) => $page->id === $target->id)->prepend($target)->values();
        }

        return view('livewire.product-pages', [
            'product' => $product, 'pages' => $pages, 'available' => $available, 'canEdit' => $this->canEdit(),
            'templates' => Templates::choices(), 'labels' => collect(Templates::choices())->pluck('label', 'key'),
        ]);
    }

    private function product(): ?Product
    {
        $query = Product::query()->whereKey($this->productId)
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));

        return Membership::of(auth()->user(), $this->currentWorkspace())?->scopeBrands($query)->with('brand')->first();
    }

    private function canEdit(): bool
    {
        return (bool) Membership::of(auth()->user(), $this->currentWorkspace())?->canCreate();
    }
}
