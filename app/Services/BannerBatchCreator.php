<?php

namespace App\Services;

use App\Models\BannerCreative;
use App\Models\BannerGenerationBatch;
use App\Models\CampaignPack;
use App\Models\CampaignPackVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BannerBatchCreator
{
    public function __construct(private BannerCopySelector $copy) {}

    public function createIncluded(Workspace $workspace, CampaignPack $pack, User $user): BannerGenerationBatch
    {
        return DB::transaction(function () use ($workspace, $pack, $user): BannerGenerationBatch {
            $lockedPack = CampaignPack::query()->lockForUpdate()->findOrFail($pack->id);
            $this->ensureEligible($workspace, $lockedPack);

            $existing = $lockedPack->bannerGenerationBatches()->where('kind', 'included')->first();
            if ($existing) {
                return $existing;
            }
            $this->ensureNoActiveBatch($lockedPack);
            $version = $this->approvedVersion($lockedPack);
            $count = (int) config('campaigns.banners.included_count');
            $batch = BannerGenerationBatch::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $lockedPack->id,
                'campaign_pack_version_id' => $version->id,
                'requested_by_user_id' => $user->id,
                'kind' => 'included',
                'included_key' => 'campaign-pack:'.$lockedPack->id.':included',
                'requested_count' => $count,
                'credit_cost' => 0,
            ]);
            for ($sequence = 1; $sequence <= $count; $sequence++) {
                $this->createCreative($batch, $version, $sequence);
            }

            return $batch->load('creatives');
        });
    }

    public function createAdditional(Workspace $workspace, CampaignPack $pack, User $user): BannerGenerationBatch
    {
        return DB::transaction(function () use ($workspace, $pack, $user): BannerGenerationBatch {
            $lockedWorkspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $lockedPack = CampaignPack::query()->lockForUpdate()->findOrFail($pack->id);
            $this->ensureEligible($lockedWorkspace, $lockedPack);
            $this->ensureNoActiveBatch($lockedPack);
            $creditCost = (int) config('campaigns.banners.additional_credit_cost');
            if ($lockedWorkspace->creditBalance() < $creditCost) {
                throw ValidationException::withMessages(['banner' => 'This workspace does not have enough credits for another banner.']);
            }

            $version = $this->approvedVersion($lockedPack);
            $sequence = $lockedPack->bannerCreatives()->count() + 1;
            $batch = BannerGenerationBatch::create([
                'workspace_id' => $lockedWorkspace->id,
                'campaign_pack_id' => $lockedPack->id,
                'campaign_pack_version_id' => $version->id,
                'requested_by_user_id' => $user->id,
                'kind' => 'additional',
                'requested_count' => 1,
                'credit_cost' => $creditCost,
            ]);
            $this->createCreative($batch, $version, $sequence);
            $lockedWorkspace->credits()->create([
                'campaign_pack_id' => $lockedPack->id,
                'banner_generation_batch_id' => $batch->id,
                'amount' => -$creditCost,
                'event' => 'banner_generation',
                'description' => 'Additional campaign banner',
                'idempotency_key' => 'banner-generation:'.$batch->id,
            ]);

            return $batch->load('creatives');
        });
    }

    public function retryIncluded(Workspace $workspace, CampaignPack $pack): BannerGenerationBatch
    {
        return DB::transaction(function () use ($workspace, $pack): BannerGenerationBatch {
            $lockedPack = CampaignPack::query()->lockForUpdate()->findOrFail($pack->id);
            $this->ensureEligible($workspace, $lockedPack);
            $this->ensureNoActiveBatch($lockedPack);
            $batch = $lockedPack->bannerGenerationBatches()->where('kind', 'included')->firstOrFail();
            $failed = $batch->creatives()->where('status', 'failed')->get();
            if ($failed->isEmpty()) {
                throw ValidationException::withMessages(['banner' => 'There are no failed included banners to retry.']);
            }
            $batch->update(['status' => 'queued', 'error_code' => null, 'error_message' => null, 'completed_at' => null]);
            $batch->creatives()->whereKey($failed->modelKeys())->update([
                'status' => 'queued', 'attempts' => 0, 'error_code' => null, 'error_message' => null,
                'started_at' => null, 'completed_at' => null,
            ]);

            return $batch->fresh('creatives');
        });
    }

    private function createCreative(BannerGenerationBatch $batch, CampaignPackVersion $version, int $sequence): BannerCreative
    {
        $copy = $this->copy->select($version, $sequence);

        return $batch->creatives()->create([
            'campaign_pack_id' => $batch->campaign_pack_id,
            'campaign_pack_version_id' => $version->id,
            'sequence' => $sequence,
            'direction' => $copy['label'],
            'layout' => $copy['layout'],
            'headline' => $copy['headline'],
            'supporting_text' => $copy['supporting_text'],
            'cta' => $copy['cta'],
            'prompt' => $copy['prompt'],
            'disk' => config('campaigns.banners.disk'),
        ]);
    }

    private function ensureEligible(Workspace $workspace, CampaignPack $pack): void
    {
        abort_unless($pack->product->brand->workspace_id === $workspace->id, 404);
        if (! config('campaigns.banners.enabled')) {
            abort(404);
        }
        if ($pack->status !== 'approved' || $pack->current_version < 1) {
            throw ValidationException::withMessages(['banner' => 'Approve the current campaign-pack version before generating banners.']);
        }
        if (! $pack->product->mediaAssets()
            ->where('type', 'image')
            ->where('metadata->origin', 'product_page')
            ->exists()) {
            throw ValidationException::withMessages(['banner' => 'No usable product image was found on the product page.']);
        }
    }

    private function approvedVersion(CampaignPack $pack): CampaignPackVersion
    {
        $version = $pack->versions()->where('version', $pack->current_version)->firstOrFail();
        if ($version->review_status !== 'approved') {
            throw ValidationException::withMessages(['banner' => 'Only the current approved campaign-pack version can generate banners.']);
        }

        return $version;
    }

    private function ensureNoActiveBatch(CampaignPack $pack): void
    {
        if ($pack->bannerGenerationBatches()->whereIn('status', ['queued', 'running', 'retrying'])->exists()) {
            throw ValidationException::withMessages(['banner' => 'A banner request is already running for this campaign pack.']);
        }
    }
}
