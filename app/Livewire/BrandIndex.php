<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use Livewire\Component;

class BrandIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();

        return view('livewire.brand-index', [
            'workspace' => $workspace,
            'brands' => $workspace->brands()->withCount(['products'])->orderBy('name')->get(),
            'brandLimit' => (int) config('campaigns.brand_limit'),
            'profileAvailable' => Brand::profileAvailable(),
        ])->layout('components.layouts.app');
    }
}
