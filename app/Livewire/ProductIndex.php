<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Product;
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
        $products = Product::query()
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($this->brand, fn ($query) => $query->where('brand_id', $this->brand))
            ->with(['brand', 'campaignPacks', 'sourceSnapshots'])
            ->withCount('campaignPacks')
            ->latest()
            ->get();

        $brands = $workspace->brands()->orderBy('name')->get(['id', 'name']);

        return view('livewire.product-index', compact('workspace', 'products', 'brands'))
            ->layout('components.layouts.app');
    }
}
