<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use Livewire\Component;

class UsageIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $jobsQuery = $workspace->generationJobs();

        return view('livewire.usage-index', [
            'workspace' => $workspace,
            'subscription' => $workspace->subscription,
            'canManageBilling' => auth()->user()->workspaces()
                ->whereKey($workspace->id)
                ->wherePivot('role', 'owner')
                ->exists(),
            'billingConfigured' => filled(config('services.stripe.secret')) && filled(config('services.stripe.price_id')),
            'jobs' => (clone $jobsQuery)->with('campaignPack.product.brand')->latest()->limit(25)->get(),
            'creditEvents' => $workspace->credits()->with('campaignPack')->latest()->limit(20)->get(),
            'creditBalance' => $workspace->creditBalance(),
            'creditsSpent' => abs((int) $workspace->credits()->where('amount', '<', 0)->sum('amount')),
            'totalCost' => (float) (clone $jobsQuery)->sum('estimated_cost'),
            'costAlerts' => (clone $jobsQuery)->where('cost_alert', true)->count(),
            'completedJobs' => (clone $jobsQuery)->where('status', 'completed')->count(),
            'cogsTarget' => config('campaigns.cogs_target'),
            'cogsAlert' => config('campaigns.cogs_alert'),
        ])->layout('components.layouts.app');
    }
}
