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

        return view('livewire.product-index', compact('workspace', 'products', 'brands', 'canCreate'))
            ->layout('components.layouts.app');
    }
}
