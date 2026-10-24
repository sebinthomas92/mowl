<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\ContentItem;
use App\Models\Product;
use App\Services\ContentLibrary;
use App\Support\Membership;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class GlobalSearch extends Component
{
    use InteractsWithWorkspace;

    public string $query = '';

    public function render()
    {
        $term = trim($this->query);
        $results = [];

        if (mb_strlen($term) >= 2) {
            $workspace = $this->currentWorkspace();
            $membership = Membership::of(auth()->user(), $workspace);
            $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
            $matches = fn (Builder $query, string $column) => $query->whereRaw("lower({$column}) like ? escape '\\'", [$like]);

            $results = [
                'Products' => $membership->scopeBrands(Product::query())
                    ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->where(fn ($query) => $matches($query, 'products.name'))
                    ->with('brand')->orderBy('name')->limit(5)->get()
                    ->map(fn (Product $product) => ['label' => $product->name, 'meta' => $product->brand->name, 'url' => route('products.show', $product), 'icon' => 'package']),
                'Brands' => $membership->scopeBrands(Brand::query(), 'id')
                    ->where('workspace_id', $workspace->id)
                    ->where(fn ($query) => $matches($query, 'brands.name'))
                    ->withCount('products')->orderBy('name')->limit(5)->get()
                    ->map(fn (Brand $brand) => ['label' => $brand->name, 'meta' => trans_choice(':count product|:count products', $brand->products_count), 'url' => route('brands.index'), 'icon' => 'building-store']),
                'Launch packs' => $membership->scopeThroughProduct(CampaignPack::query())
                    ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->where(fn ($query) => $matches($query, 'campaign_packs.name'))
                    ->with('product')->latest()->limit(5)->get()
                    ->map(fn (CampaignPack $pack) => ['label' => $pack->name ?: $pack->product->name, 'meta' => str($pack->status)->title(), 'url' => route('campaign-packs.show', $pack), 'icon' => 'stack-2']),
            ];
            if (ContentLibrary::available()) {
                $results['Outputs'] = $membership->scopeThroughProduct(ContentItem::query())
                    ->where('workspace_id', $workspace->id)
                    ->where(fn ($query) => $matches($query, 'content_items.title'))
                    ->with('product')->latest('updated_at')->limit(5)->get()
                    ->map(fn (ContentItem $item) => ['label' => $item->title, 'meta' => $item->product->name.' · v'.$item->current_version, 'url' => route('products.show', [$item->product_id, 'tab' => 'content', 'item' => $item->id]), 'icon' => ['image' => 'photo', 'video' => 'movie'][$item->type] ?? 'file-text']);
            }
            $results = array_filter($results, fn ($group) => $group->isNotEmpty());
        }

        return view('livewire.global-search', ['results' => $results, 'term' => $term]);
    }
}
