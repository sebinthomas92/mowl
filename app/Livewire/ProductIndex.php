<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Product;
use App\Services\ContentLibrary;
use App\Services\ProductKnowledge;
use App\Support\Membership;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductIndex extends Component
{
    use InteractsWithWorkspace;

    #[Url]
    public ?int $brand = null;

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);
        $products = $membership->scopeBrands(Product::query())
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($this->brand, fn ($query) => $query->where('brand_id', $this->brand))
            ->with(['brand', 'campaignPacks.versions:id,campaign_pack_id,review_status', 'hubShares' => fn ($query) => $query->whereNull('revoked_at')])
            ->withCount('campaignPacks')
            ->when(ContentLibrary::available(), fn ($query) => $query->withCount([
                'contentItems as in_review_count' => fn ($items) => $items->where('status', 'review'),
                'contentItems as changes_count' => fn ($items) => $items->where('status', 'changes'),
            ]))
            ->when(ProductKnowledge::available(), fn ($query) => $query->withCount([
                'facts as conflicts_count' => fn ($facts) => $facts->where('status', 'conflict'),
            ]))
            ->latest()
            ->get();

        $brands = $membership->scopeBrands($workspace->brands()->getQuery(), 'id')->orderBy('name')->get(['id', 'name']);
        $canCreate = $membership->canCreate();

        $gettingStarted = $this->gettingStarted($products, $canCreate, $workspace->isAgency());

        return view('livewire.product-index', compact('workspace', 'products', 'brands', 'canCreate', 'gettingStarted'))
            ->layout('components.layouts.app')->title('Products');
    }

    /**
     * The path from an empty workspace to a first shared launch pack. Shown until someone has
     * done it once, then never again. Null when there is nothing left to guide.
     */
    private function gettingStarted($products, bool $canCreate, bool $agency): ?array
    {
        if ($this->brand) {
            return null;
        }
        $first = $products->sortBy('id')->first();
        $withPack = $products->first(fn (Product $product) => $product->campaign_packs_count > 0);
        $approved = $products->first(fn (Product $product) => $product->campaignPacks->contains(fn ($pack) => $pack->versions->contains('review_status', 'approved')));
        $shared = $products->contains(fn (Product $product) => $product->hubShares->isNotEmpty());
        $packOf = fn (?Product $product) => $product?->campaignPacks->sortByDesc('id')->first();

        $steps = [
            ['title' => 'Add a product', 'hint' => 'Paste its page address and we read the name, price and details for you to check.', 'done' => (bool) $first,
                'label' => $canCreate ? 'Add product' : null, 'url' => route('products.create')],
            ['title' => 'Write a launch pack', 'hint' => 'Positioning, ad angles, three video scripts and channel copy, written only from the product\'s facts. 1 credit, about two minutes.', 'done' => (bool) $withPack,
                'label' => $canCreate && $first ? 'Create launch pack' : null, 'url' => $first ? route('campaign-packs.create', ['product' => $first->id]) : null],
            ['title' => 'Check and approve it', 'hint' => 'Approve the product facts, then each output. Approving never publishes anything.', 'done' => (bool) $approved,
                'label' => $withPack ? 'Open launch pack' : null, 'url' => $withPack ? route('campaign-packs.show', $packOf($withPack)) : null],
            ['title' => $agency ? 'Make banners and share with your client' : 'Make banners and share', 'hint' => $agency ? 'Your client gets one link with the approved copy and images.' : 'Download images and copy lines, or send one link to anyone helping you launch.', 'done' => $shared,
                'label' => $approved ? 'Open Share' : null, 'url' => $approved ? route('products.show', [$approved, 'tab' => 'hub']) : null],
        ];

        return collect($steps)->contains('done', false) && ! $shared ? $steps : null;
    }
}
