<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\BannerGenerationBatch;
use App\Models\CampaignGenerationJob;
use Illuminate\Support\Collection;
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
        $jobs = (clone $jobsQuery)->with('campaignPack.product.brand')->latest()->limit(30)->get();
        $bannerBatches = $bannerBatchesQuery
            ? (clone $bannerBatchesQuery)->with('campaignPack.product.brand')->latest()->limit(30)->get()
            : collect();

        $creditBalance = $workspace->spendableCredits();
        $creditsSpent = abs((int) $workspace->credits()->where('amount', '<', 0)->sum('amount'));
        $creditsRefunded = (int) $workspace->credits()->where('amount', '>', 0)->where('event', 'like', '%refund%')->sum('amount');

        return view('livewire.usage-index', [
            'workspace' => $workspace,
            'runs' => $this->runs($workspace, $jobs, $bannerBatches),
            'creditEvents' => $workspace->credits()->with('campaignPack')->latest()->limit(20)->get(),
            'creditBalance' => $creditBalance,
            'creditsSpent' => $creditsSpent,
            'creditsUsed' => max(0, $creditsSpent - $creditsRefunded),
            'totalCost' => (float) (clone $jobsQuery)->sum('estimated_cost') + (float) ($bannerBatchesQuery ? (clone $bannerBatchesQuery)->sum('estimated_cost') : 0),
            'costAlerts' => (clone $jobsQuery)->where('cost_alert', true)->count() + ($bannerBatchesQuery ? (clone $bannerBatchesQuery)->where('cost_alert', true)->count() : 0),
            'completedJobs' => (clone $jobsQuery)->where('status', 'completed')->count() + ($bannerBatchesQuery ? (clone $bannerBatchesQuery)->where('status', 'completed')->count() : 0),
            'cogsTarget' => config('campaigns.cogs_target'),
            'cogsAlert' => config('campaigns.cogs_alert'),
        ])->layout('components.layouts.app')->title('Usage');
    }

    /**
     * Each generation run with what it was expected to cost before it started (the credits quoted)
     * and what it ended up costing (credits charged minus refunds, plus provider spend).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function runs($workspace, Collection $jobs, Collection $bannerBatches): Collection
    {
        $net = fn (string $column, Collection $ids) => $ids->isEmpty() ? collect() : $workspace->credits()
            ->whereIn($column, $ids)
            ->selectRaw("{$column} as run_id, sum(amount) as net")
            ->groupBy($column)
            ->pluck('net', 'run_id');

        $jobNet = $net('campaign_generation_job_id', $jobs->pluck('id'));
        $batchNet = $net('banner_generation_batch_id', $bannerBatches->pluck('id'));

        $rows = $jobs->map(fn (CampaignGenerationJob $job) => [
            'key' => 'job-'.$job->id,
            'product' => $job->campaignPack?->product?->name ?? 'Launch pack',
            'pack' => $job->campaignPack,
            'what' => $job->section
                ? 'Rewrite · '.str_replace('_', ' ', $job->section)
                : (in_array($job->analysis_mode, ['deep', 'premium'], true) ? 'Launch pack · deep' : 'Launch pack'),
            'at' => $job->created_at,
            'status' => $job->status,
            'estimate' => (int) $job->credit_cost,
            'actual' => $jobNet->has($job->id) ? max(0, -1 * (int) $jobNet[$job->id]) : null,
            'cost' => (float) $job->estimated_cost,
            'alert' => (bool) $job->cost_alert,
            'detail' => $job->status === 'failed' ? ($job->error_message ?: 'Generation failed. Credits were returned.') : null,
        ]);

        $rows = $rows->concat($bannerBatches->map(fn (BannerGenerationBatch $batch) => [
            'key' => 'batch-'.$batch->id,
            'product' => $batch->campaignPack?->product?->name ?? 'Launch pack',
            'pack' => $batch->campaignPack,
            'what' => trans_choice('Banner studio · :count image|Banner studio · :count images', (int) $batch->requested_count),
            'at' => $batch->created_at,
            'status' => $batch->status,
            'estimate' => (int) $batch->credit_cost,
            'actual' => $batchNet->has($batch->id) ? max(0, -1 * (int) $batchNet[$batch->id]) : null,
            'cost' => (float) $batch->estimated_cost,
            'alert' => (bool) $batch->cost_alert,
            'detail' => in_array($batch->status, ['failed', 'partial'], true) ? ($batch->error_message ?: 'Some images failed. Their credits were returned.') : null,
        ]));

        return $rows->sortByDesc('at')->take(30)->values();
    }
}
