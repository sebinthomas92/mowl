<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\CampaignPack;
use App\Support\Membership;
use Livewire\Attributes\Url;
use Livewire\Component;

class CampaignPackIndex extends Component
{
    use InteractsWithWorkspace;

    /** Set to "review" by the top bar's review queue link. */
    #[Url]
    public string $status = '';

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);
        $packs = $membership->scopeThroughProduct(CampaignPack::query())
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($this->status === 'review', fn ($query) => $query->where('status', 'review'))
            ->with(['product.brand', 'latestGenerationJob'])
            ->latest()
            ->get();

        $canCreate = $membership->canCreate();
        $canSeeCosts = $membership->canSeeCosts();

        return view('livewire.campaign-pack-index', compact('workspace', 'packs', 'canCreate', 'canSeeCosts'))
            ->layout('components.layouts.app')->title('Launch packs');
    }
}
