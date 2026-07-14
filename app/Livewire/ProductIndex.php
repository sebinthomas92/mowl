<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Product;
use Livewire\Component;

class ProductIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $products = Product::query()
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['brand', 'campaignPacks', 'sourceSnapshots'])
            ->withCount('campaignPacks')
            ->latest()
            ->get();

        return view('livewire.product-index', compact('workspace', 'products'))
            ->layout('components.layouts.app');
    }
}
