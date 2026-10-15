<?php

namespace App\Services;

use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Queues a new version of one pack section. The first three in a pack's first day are included. */
class SectionRegenerator
{
    public const SECTIONS = ['positioning', 'ranked_angles', 'creative_routes', 'offers', 'marketing_hub', 'summary'];

    /** Pack content keys each section replaces; "summary" is the overview and product truth together. */
    public const KEYS = ['summary' => ['overview', 'product_truth']];

    /** @return list<string> */
    public static function keys(string $section): array
    {
        return self::KEYS[$section] ?? [$section];
    }

    public const READY_STATUSES = ['draft', 'review', 'rejected', 'approved'];

    public function request(Workspace $workspace, CampaignPack $pack, string $section, string $errorKey = 'regenerationSection'): CampaignGenerationJob
    {
        abort_unless(in_array($section, self::SECTIONS, true), 422);
        abort_unless(in_array($pack->status, self::READY_STATUSES, true) && $pack->current_version > 0, 422);

        $job = DB::transaction(function () use ($workspace, $pack, $section, $errorKey): CampaignGenerationJob {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            $lockedPack = CampaignPack::query()->lockForUpdate()->findOrFail($pack->id);
            abort_unless(in_array($lockedPack->status, self::READY_STATUSES, true) && $lockedPack->current_version > 0, 422);
            if ($lockedPack->generationJobs()->whereIn('status', ['queued', 'processing', 'retrying'])->exists()) {
                throw ValidationException::withMessages([$errorKey => 'A generation job is already running for this launch pack.']);
            }

            $creditCost = $this->creditCost($lockedPack);
            if ($creditCost > 0 && $lockedWorkspace->creditBalance() < $creditCost) {
                throw ValidationException::withMessages([$errorKey => 'This workspace does not have enough credits for another regeneration.']);
            }

            $job = CampaignGenerationJob::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $lockedPack->id,
                'source_snapshot_id' => $lockedPack->source_snapshot_id,
                'analysis_mode' => $lockedPack->analysis_mode,
                'section' => $section,
                'base_version' => $lockedPack->current_version,
                'credit_cost' => $creditCost,
            ]);

            if ($creditCost > 0) {
                $workspace->credits()->create([
                    'campaign_pack_id' => $lockedPack->id,
                    'campaign_generation_job_id' => $job->id,
                    'amount' => -$creditCost,
                    'event' => 'section_regeneration',
                    'description' => 'Regenerated '.$section.' section',
                    'metadata' => ['section' => $section],
                ]);
            }

            return $job;
        });

        app(CampaignJobDispatcher::class)->dispatch($job->id);

        return $job;
    }

    public function creditCost(CampaignPack $pack): int
    {
        return $this->includedRemaining($pack) > 0 ? 0 : 1;
    }

    public function includedRemaining(CampaignPack $pack): int
    {
        if (now()->gt($pack->created_at->copy()->addDay())) {
            return 0;
        }
        $used = $pack->generationJobs()
            ->whereNotNull('section')
            ->where('status', 'completed')
            ->where('created_at', '<=', $pack->created_at->copy()->addDay())
            ->count();

        return max(0, 3 - $used);
    }
}
