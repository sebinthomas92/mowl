<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class UsageIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $jobsQuery = $workspace->generationJobs();
        $bannerBatchesQuery = Schema::hasTable('banner_generation_batches')
            ? $workspace->bannerGenerationBatches()
            : null;
        $bannerBatches = $bannerBatchesQuery
            ? (clone $bannerBatchesQuery)->with('campaignPack.product.brand')->latest()->limit(25)->get()
            : collect();

        return view('livewire.usage-index', [
            'workspace' => $workspace,
            'jobs' => (clone $jobsQuery)->with('campaignPack.product.brand')->latest()->limit(25)->get(),
            'bannerBatches' => $bannerBatches,
            'creditEvents' => $workspace->credits()->with('campaignPack')->latest()->limit(20)->get(),
            'creditBalance' => $workspace->creditBalance(),
            'creditsSpent' => abs((int) $workspace->credits()->where('amount', '<', 0)->sum('amount')),
            'totalCost' => (float) (clone $jobsQuery)->sum('estimated_cost') + (float) ($bannerBatchesQuery ? (clone $bannerBatchesQuery)->sum('estimated_cost') : 0),
            'costAlerts' => (clone $jobsQuery)->where('cost_alert', true)->count() + ($bannerBatchesQuery ? (clone $bannerBatchesQuery)->where('cost_alert', true)->count() : 0),
            'completedJobs' => (clone $jobsQuery)->where('status', 'completed')->count() + ($bannerBatchesQuery ? (clone $bannerBatchesQuery)->where('status', 'completed')->count() : 0),
            'cogsTarget' => config('campaigns.cogs_target'),
            'cogsAlert' => config('campaigns.cogs_alert'),
        ])->layout('components.layouts.app');
    }
}
