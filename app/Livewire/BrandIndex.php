<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use App\Support\Membership;
use Livewire\Component;

class BrandIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);

        return view('livewire.brand-index', [
            'workspace' => $workspace,
            'brands' => $membership->scopeBrands($workspace->brands()->getQuery(), 'id')->withCount(['products'])->orderBy('name')->get(),
            'canCreate' => $membership->canCreate(),
            'canCreateBrands' => $membership->canCreateBrands(),
            'brandLimit' => (int) config('campaigns.brand_limit'),
            'profileAvailable' => Brand::profileAvailable(),
        ])->layout('components.layouts.app');
    }
}
