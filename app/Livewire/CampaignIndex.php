<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignPlanner;
use App\Services\Campaigns\CampaignResults;
use App\Support\Membership;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Every campaign in the workspace. Campaigns live inside each product; this list gathers them. */
class CampaignIndex extends Component
{
    use InteractsWithWorkspace;

    #[Url(as: 'status')]
    public string $status = 'all';

    public function render(CampaignResults $results)
    {
        $workspace = $this->currentWorkspace();
        $available = CampaignPlanner::available();
        $campaigns = collect();
        $counts = [];
        if ($available) {
            $query = Campaign::query()->where('workspace_id', $workspace->id)
                ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id));
            Membership::of(auth()->user(), $workspace)?->scopeThroughProduct($query);
            $all = $query->with(['product.brand', 'results'])->orderByRaw("case status when 'active' then 0 when 'paused' then 1 when 'planned' then 2 else 3 end")->orderByDesc('id')->get();
            $counts = $all->countBy('status')->all();
            $this->status = array_key_exists($this->status, Campaign::STATUSES) ? $this->status : 'all';
            $campaigns = $this->status === 'all' ? $all : $all->where('status', $this->status)->values();
        }

        return view('livewire.campaign-index', [
            'workspace' => $workspace,
            'available' => $available,
            'campaigns' => $campaigns,
            'counts' => $counts,
            'summaries' => $campaigns->mapWithKeys(fn (Campaign $campaign) => [$campaign->id => $results->summary($campaign, $campaign->results)]),
        ])->layout('components.layouts.app');
    }
}
