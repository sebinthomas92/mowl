<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\CampaignPack;
use Livewire\Component;

class CampaignPackIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $packs = CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['product.brand'])
            ->latest()
            ->get();

        return view('livewire.campaign-pack-index', compact('workspace', 'packs'))
            ->layout('components.layouts.app');
    }
}
