<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use Livewire\Component;

class BrandIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();

        return view('livewire.brand-index', [
            'workspace' => $workspace,
            'brands' => $workspace->brands()->withCount(['products'])->latest()->get(),
        ])->layout('components.layouts.app');
    }
}
