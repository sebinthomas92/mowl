<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\BannerCreative;
use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\CampaignPackShare;
use App\Models\CampaignPackVersion;
use App\Models\CampaignPackVersionComment;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Notifications\CampaignPackActivity;
use App\Services\BannerBatchCreator;
use App\Services\BannerJobDispatcher;
use App\Services\CampaignJobDispatcher;
use App\Services\CampaignPackContentNormalizer;
use App\Services\CampaignPackSafetyValidator;
use App\Services\ContentLibrary;
use App\Services\ProductKnowledge;
use App\Services\SectionRegenerator;
use App\Support\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url as QueryParameter;
use Livewire\Component;
use Livewire\WithFileUploads;

class CampaignWorkspace extends Component
{
    use InteractsWithWorkspace;
    use WithFileUploads;

    public const OBJECTIVES = [
        'launch' => ['Launch an offer', 'A new or refreshed offer'],
        'refresh' => ['Refresh creative', 'A new angle on the same offer'],
        'explain' => ['Explain the product', 'Education-first content'],
    ];

    public const CREDIT_COSTS = ['standard' => 1, 'deep' => 3];

    public int $step = 1;

    public string $objective = 'launch';

    public string $briefOffer = '';

    public string $briefAudience = '';

    public string $briefMessage = '';

    public string $sourceUrl = '';

    public string $analysisMode = 'standard';

    public string $regenerationSection = 'ranked_angles';

    public ?int $selectedVersion = null;

    public $bannerLogo = null;

    public string $bannerPrimaryColor = '#F4B942';

    public ?int $brandId = null;

    #[QueryParameter(as: 'product')]
    public ?int $productId = null;

    #[Locked]
    public ?int $packId = null;

    public string $commentBody = '';

    public string $commentSection = '';

    public string $reviewNote = '';

    public ?string $shareUrl = null;

    public function mount(?CampaignPack $pack = null): void
    {
        if (! $pack?->exists) {
            if ($this->productId) {
                $this->chooseProduct($this->productId);
            }

            return;
        }

        abort_unless($this->scopedPacks()->whereKey($pack->id)->exists(), 404);
        $this->packId = $pack->id;
        $this->productId = $pack->product_id;
        $this->brandId = $pack->product->brand_id;
        $this->bannerPrimaryColor = $pack->product->brand->primary_color ?: '#F4B942';
        $this->step = 4;
        // Packs made before content items existed get their items on first open.
        app(ContentLibrary::class)->syncPack($pack);
    }

    /** Prefills the brief from confirmed Knowledge so nothing is retyped. */
    public function chooseProduct(int $productId): void
    {
        $product = $this->workspaceProduct($productId);
        $this->productId = $product->id;
        $this->brandId = $product->brand_id;
        $source = $product->sourceSnapshots()->where('status', 'ready')->latest('id')->first() ?? $product->sourceSnapshots()->latest('id')->first();
        $this->sourceUrl = (string) ($source?->canonical_url ?: $source?->url);

        $facts = ProductKnowledge::available() ? $product->facts()->where('status', 'ok')->pluck('confirmed_value', 'key') : collect();
        $this->briefOffer = mb_substr(trim((string) ($facts['price'] ?? $product->price)), 0, 200);
        $profile = ProductKnowledge::available() ? app(ProductKnowledge::class)->brandProfile($product) : [];
        $this->briefAudience = mb_substr(trim((string) ($facts['audience'] ?? $profile['default_audience'] ?? '')), 0, 300);
        $this->step = 3;
        $this->resetValidation();
    }

    public function changeProduct(): void
    {
        $this->productId = null;
        $this->step = 1;
    }

    public function generatePack(CampaignJobDispatcher $dispatcher): void
    {
        abort_unless($this->productId, 422);
        $data = $this->validate([
            'sourceUrl' => ['required', 'url:http,https', 'max:2000'],
            'analysisMode' => ['required', 'in:standard,deep'],
            'objective' => ['required', 'in:'.implode(',', array_keys(self::OBJECTIVES))],
            'briefOffer' => ['nullable', 'string', 'max:200'],
            'briefAudience' => ['nullable', 'string', 'max:300'],
            'briefMessage' => ['nullable', 'string', 'max:300'],
        ], [], ['sourceUrl' => 'product page URL', 'briefOffer' => 'offer', 'briefAudience' => 'audience', 'briefMessage' => 'key message']);
        $workspace = $this->currentWorkspace();
        $creditCost = self::CREDIT_COSTS[$data['analysisMode']];
        $brief = array_filter([
            'objective' => self::OBJECTIVES[$data['objective']][0],
            'offer' => trim((string) $data['briefOffer']),
            'audience' => trim((string) $data['briefAudience']),
            'message' => trim((string) $data['briefMessage']),
        ], 'filled');

        [$pack, $generationJob] = DB::transaction(function () use ($data, $workspace, $creditCost, $brief): array {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            if ($lockedWorkspace->creditBalance() < $creditCost) {
                throw ValidationException::withMessages(['sourceUrl' => 'This workspace does not have enough pack credits.']);
            }

            $product = $this->scopedProducts()->findOrFail($this->productId);
            $source = $product->sourceSnapshots()->latest()->first();
            if (! $source || ! in_array($data['sourceUrl'], [$source->url, $source->canonical_url], true)) {
                $source = SourceSnapshot::create([
                    'product_id' => $product->id,
                    'url' => $data['sourceUrl'],
                    'content_hash' => hash('sha256', $data['sourceUrl']),
                    'status' => 'pending',
                    'refreshed_from_snapshot_id' => $source?->id,
                ]);
            }

            $pack = CampaignPack::create([
                'product_id' => $product->id,
                'source_snapshot_id' => $source->id,
                'name' => "{$product->name} launch pack",
                'status' => 'queued',
                'analysis_mode' => $data['analysisMode'],
                'credit_cost' => $creditCost,
                'current_version' => 0,
                'estimated_cost' => 0,
            ] + (Schema::hasColumn('campaign_packs', 'brief') ? ['brief' => $brief] : []));

            $generationJob = CampaignGenerationJob::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $pack->id,
                'source_snapshot_id' => $source->id,
                'analysis_mode' => $data['analysisMode'],
                'credit_cost' => $creditCost,
            ]);
            $workspace->credits()->create([
                'campaign_pack_id' => $pack->id,
                'campaign_generation_job_id' => $generationJob->id,
                'amount' => -$creditCost,
                'event' => 'pack_generation',
                'description' => ucfirst($data['analysisMode']).' launch pack generation',
            ]);

            return [$pack, $generationJob];
        });

        $dispatcher->dispatch($generationJob->id);
        $this->packId = $pack->id;
        $this->step = 4;
        $this->redirectRoute('campaign-packs.show', ['pack' => $pack], navigate: true);
    }

    public function retryGeneration(CampaignJobDispatcher $dispatcher): void
    {
        $workspace = $this->currentWorkspace();
        $pack = CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->findOrFail($this->packId);

        $job = DB::transaction(function () use ($workspace, $pack): CampaignGenerationJob {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            $lockedPack = CampaignPack::query()->lockForUpdate()->findOrFail($pack->id);
            abort_unless($lockedPack->status === 'failed', 422);

            if ($lockedWorkspace->creditBalance() < $lockedPack->credit_cost) {
                throw ValidationException::withMessages(['sourceUrl' => 'This workspace does not have enough pack credits to retry.']);
            }

            $job = CampaignGenerationJob::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $lockedPack->id,
                'source_snapshot_id' => $lockedPack->source_snapshot_id,
                'analysis_mode' => $lockedPack->analysis_mode,
                'credit_cost' => $lockedPack->credit_cost,
            ]);
            $workspace->credits()->create([
                'campaign_pack_id' => $lockedPack->id,
                'campaign_generation_job_id' => $job->id,
                'amount' => -$lockedPack->credit_cost,
                'event' => 'generation_retry',
                'description' => 'Launch pack generation retry',
            ]);
            $lockedPack->update(['status' => 'queued']);

            return $job;
        });

        $dispatcher->dispatch($job->id);
    }

    public function regenerateSection(SectionRegenerator $regenerator): void
    {
        $data = $this->validate([
            'regenerationSection' => ['required', 'in:'.implode(',', SectionRegenerator::SECTIONS)],
        ]);
        $pack = $this->workspacePack();
        $job = $regenerator->request($this->currentWorkspace(), $pack, $data['regenerationSection']);
        $this->selectedVersion = null;
        $this->audit('campaign_pack_regeneration_requested', $pack, null, ['section' => $data['regenerationSection'], 'generation_job_id' => $job->id]);
    }

    public function selectVersion(int $version): void
    {
        $pack = CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id))
            ->findOrFail($this->packId);
        abort_unless($pack->versions()->where('version', $version)->exists(), 404);

        $this->selectedVersion = $version;
    }

    public function requestReview(): void
    {
        $version = $this->selectedPackVersion();
        abort_unless($version->review_status === 'draft', 422);
        $version->update(['review_status' => 'review']);
        $version->campaignPack->update(['status' => 'review']);
        $this->audit('campaign_pack_review_requested', $version);
        $this->notifyWorkspace('review_requested', $version);
    }

    public function approveVersion(): void
    {
        $this->ensureReviewer();
        $version = $this->selectedPackVersion();
        abort_unless(in_array($version->review_status, $this->decidableStatuses()), 422);
        app(CampaignPackSafetyValidator::class)->assertApprovable($version, $version->campaignPack->sourceSnapshot);
        $version->update([
            'review_status' => 'approved',
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $this->reviewNote ?: null,
        ]);
        $version->campaignPack->update(['status' => 'approved']);
        $this->audit('campaign_pack_version_approved', $version, $this->reviewNote ?: null);
        $this->notifyWorkspace('approved', $version);
        $this->reviewNote = '';
    }

    /** Owners may decide on a draft directly; reviewers only decide on what was submitted. */
    private function decidableStatuses(): array
    {
        return Membership::of(auth()->user(), $this->currentWorkspace())?->isOwner() ? ['draft', 'review'] : ['review'];
    }

    public function rejectVersion(): void
    {
        $this->ensureReviewer();
        $data = $this->validate(['reviewNote' => ['required', 'string', 'max:2000']]);
        $version = $this->selectedPackVersion();
        abort_unless(in_array($version->review_status, $this->decidableStatuses()), 422);
        $version->update(['review_status' => 'rejected', 'reviewed_by_user_id' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $data['reviewNote']]);
        $version->campaignPack->update(['status' => 'rejected']);
        $this->audit('campaign_pack_version_rejected', $version, $data['reviewNote']);
        $this->notifyWorkspace('rejected', $version);
        $this->reviewNote = '';
    }

    public function addComment(): void
    {
        $data = $this->validate(['commentBody' => ['required', 'string', 'max:2000'], 'commentSection' => ['nullable', 'string', 'max:80']]);
        $version = $this->selectedPackVersion();
        CampaignPackVersionComment::create([
            'campaign_pack_version_id' => $version->id,
            'workspace_id' => $this->currentWorkspace()->id,
            'user_id' => auth()->id(),
            'section' => $data['commentSection'] ?: null,
            'body' => $data['commentBody'],
        ]);
        $this->audit('campaign_pack_comment_added', $version, null, ['section' => $data['commentSection'] ?: null]);
        $this->notifyWorkspace('commented', $version);
        $this->commentBody = '';
        $this->commentSection = '';
    }

    public function approveSource(): void
    {
        $this->ensureReviewer();
        $pack = $this->workspacePack();
        $pack->sourceSnapshot->update(['approved_by_user_id' => auth()->id(), 'approved_at' => now()]);
        $this->audit('source_snapshot_approved', $pack->sourceSnapshot);
    }

    public function createShare(): void
    {
        $this->ensureOwner();
        $version = $this->selectedPackVersion();
        abort_unless($version->review_status === 'approved', 422);
        $share = CampaignPackShare::create([
            'campaign_pack_id' => $version->campaign_pack_id,
            'campaign_pack_version_id' => $version->id,
            'workspace_id' => $this->currentWorkspace()->id,
            'created_by_user_id' => auth()->id(),
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);
        $this->shareUrl = route('campaign-packs.share', $share->token);
        $this->audit('campaign_pack_shared', $version, null, ['share_id' => $share->id, 'expires_at' => $share->expires_at?->toIso8601String()]);
    }

    public function revokeShare(int $shareId): void
    {
        $this->ensureOwner();
        $share = CampaignPackShare::query()->where('workspace_id', $this->currentWorkspace()->id)->findOrFail($shareId);
        $share->update(['revoked_at' => now()]);
        $this->audit('campaign_pack_share_revoked', $share);
    }

    public function saveBannerBranding(): void
    {
        $data = $this->validate([
            'bannerPrimaryColor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bannerLogo' => ['nullable', 'file', 'mimetypes:image/png,image/webp', 'max:5120'],
        ]);
        $pack = $this->workspacePack();
        $brand = $pack->product->brand;
        $attributes = ['primary_color' => $data['bannerPrimaryColor'] ?: null];

        if ($this->bannerLogo) {
            $disk = config('campaigns.banners.disk');
            $extension = strtolower($this->bannerLogo->extension() ?: 'png');
            $path = $this->bannerLogo->storeAs(
                "brand-assets/{$this->currentWorkspace()->id}/{$brand->id}",
                'banner-logo.'.$extension,
                $disk,
            );
            if ($brand->banner_logo_path && ($brand->banner_logo_path !== $path || $brand->banner_logo_disk !== $disk)) {
                Storage::disk($brand->banner_logo_disk)->delete($brand->banner_logo_path);
            }
            $attributes += [
                'banner_logo_disk' => $disk,
                'banner_logo_path' => $path,
                'banner_logo_mime_type' => $this->bannerLogo->getMimeType(),
            ];
        }

        $brand->update($attributes);
        $this->bannerLogo = null;
        $this->audit('banner_branding_updated', $brand, null, ['primary_color' => $attributes['primary_color'], 'has_logo' => (bool) $brand->fresh()->banner_logo_path]);
    }

    public function generateIncludedBanners(BannerBatchCreator $creator, BannerJobDispatcher $dispatcher): void
    {
        $pack = $this->workspacePack();
        $batch = $creator->createIncluded($this->currentWorkspace(), $pack, auth()->user());
        $dispatcher->dispatch($batch);
        $this->audit('banner_batch_requested', $batch, null, ['kind' => 'included', 'count' => $batch->requested_count]);
    }

    public function generateAdditionalBanner(BannerBatchCreator $creator, BannerJobDispatcher $dispatcher): void
    {
        $pack = $this->workspacePack();
        $batch = $creator->createAdditional($this->currentWorkspace(), $pack, auth()->user());
        $dispatcher->dispatch($batch);
        $this->audit('banner_batch_requested', $batch, null, ['kind' => 'additional', 'count' => 1, 'credit_cost' => $batch->credit_cost]);
    }

    public function retryIncludedBanners(BannerBatchCreator $creator, BannerJobDispatcher $dispatcher): void
    {
        $pack = $this->workspacePack();
        $batch = $creator->retryIncluded($this->currentWorkspace(), $pack);
        $dispatcher->dispatch($batch);
        $this->audit('banner_included_slots_retried', $batch);
    }

    private function workspaceProduct(int $productId): Product
    {
        $product = $this->scopedProducts()->find($productId);
        abort_unless($product, 404);

        return $product;
    }

    private function workspacePack(): CampaignPack
    {
        return $this->scopedPacks()
            ->with('sourceSnapshot')
            ->findOrFail($this->packId);
    }

    private function selectedPackVersion(): CampaignPackVersion
    {
        $pack = $this->workspacePack();
        $version = $this->selectedVersion ?: $pack->current_version;

        return $pack->versions()->findOrFail($pack->versions()->where('version', $version)->value('id'));
    }

    private function ensureOwner(): void
    {
        abort_unless($this->membership()?->isOwner(), 403);
    }

    private function ensureReviewer(): void
    {
        abort_unless($this->membership()?->canReview(), 403);
    }

    private function membership(): ?Membership
    {
        return Membership::of(auth()->user(), $this->currentWorkspace());
    }

    /** Products of the workspace within the member's brand scope. */
    private function scopedProducts()
    {
        $query = Product::query()->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));

        return $this->membership()?->scopeBrands($query) ?? $query->whereRaw('1 = 0');
    }

    private function scopedPacks()
    {
        $query = CampaignPack::query()->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));

        return $this->membership()?->scopeThroughProduct($query) ?? $query->whereRaw('1 = 0');
    }

    private function audit(string $event, object $subject, ?string $reason = null, array $metadata = []): void
    {
        $this->currentWorkspace()->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => $event, 'subject_type' => $subject::class,
            'subject_id' => $subject->id, 'reason' => $reason, 'metadata' => $metadata,
        ]);
    }

    /** Review requests go to owners (they approve); everything else goes to the whole workspace. */
    private function notifyWorkspace(string $event, CampaignPackVersion $version): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $recipients = Membership::audience($this->currentWorkspace(), $version->campaignPack?->product?->brand_id)->whereKeyNot(auth()->id());
        if ($event === 'review_requested') {
            $recipients->wherePivotIn('role', ['owner', 'reviewer']);
        }

        Notification::send($recipients->get(), new CampaignPackActivity($event, $version, auth()->user()));
    }

    public function startAnother(): void
    {
        $this->redirectRoute('campaign-packs.create', navigate: true);
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $reviewFeaturesAvailable = Schema::hasTable('campaign_pack_version_comments')
            && Schema::hasTable('campaign_pack_shares')
            && Schema::hasColumn('campaign_pack_versions', 'review_status')
            && Schema::hasColumn('source_snapshots', 'approved_at');
        if ($reviewFeaturesAvailable && DB::connection()->getDriverName() === 'pgsql') {
            try {
                $reviewFeaturesAvailable = (bool) DB::scalar(
                    "select has_table_privilege(current_user, 'campaign_pack_version_comments', 'SELECT')
                        and has_table_privilege(current_user, 'campaign_pack_shares', 'SELECT')"
                );
            } catch (QueryException) {
                $reviewFeaturesAvailable = false;
            }
        }
        $bannerStudioAvailable = Schema::hasTable('banner_generation_batches')
            && Schema::hasTable('banner_creatives')
            && config('campaigns.banners.enabled');
        if ($bannerStudioAvailable && DB::connection()->getDriverName() === 'pgsql') {
            try {
                $bannerStudioAvailable = (bool) DB::scalar(
                    "select has_table_privilege(current_user, 'banner_generation_batches', 'SELECT')
                        and has_table_privilege(current_user, 'banner_creatives', 'SELECT')"
                );
            } catch (QueryException) {
                $bannerStudioAvailable = false;
            }
        }
        $packRelations = [
            'product.brand',
            'sourceSnapshot',
            $reviewFeaturesAvailable ? 'versions.comments.user' : 'versions',
            'latestGenerationJob',
        ];
        if ($bannerStudioAvailable) {
            $packRelations[] = 'bannerGenerationBatches.creatives.campaignPackVersion';
        }
        $pack = $this->packId
            ? $this->scopedPacks()
                ->with($packRelations)
                ->findOrFail($this->packId)
            : null;

        if (
            $pack?->latestGenerationJob?->status === 'processing'
            && config('campaigns.processing_mode') === 'request'
            && $pack->latestGenerationJob->updated_at->lt(now()->subMinutes(config('campaigns.stale_after_minutes')))
        ) {
            $pack->latestGenerationJob->update(['status' => 'retrying', 'phase' => 'retry_wait']);
            $pack->load('latestGenerationJob');
        }

        if ($pack && $bannerStudioAvailable) {
            BannerCreative::query()
                ->where('campaign_pack_id', $pack->id)
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(config('campaigns.stale_after_minutes')))
                ->update(['status' => 'retrying']);
            $pack->load('bannerGenerationBatches.creatives.campaignPackVersion');
        }

        $nextBannerCreative = $pack && $bannerStudioAvailable
            ? $pack->bannerCreatives()->whereIn('status', ['queued', 'retrying'])->oldest()->first()
            : null;
        $selectedPackVersion = $pack
            ? ($this->selectedVersion ? $pack->versions->firstWhere('version', $this->selectedVersion) : $pack->versions->sortByDesc('version')->first())
            : null;
        $displayContent = $selectedPackVersion
            ? app(CampaignPackContentNormalizer::class)->normalize($selectedPackVersion->content ?? [], $pack->product, $pack->sourceSnapshot)
            : [];
        $qaIssues = $selectedPackVersion
            ? app(CampaignPackSafetyValidator::class)->issues(
                $displayContent,
                $selectedPackVersion->evidence ?? [],
                $selectedPackVersion->compliance_flags ?? [],
                $pack->sourceSnapshot,
            )
            : [];

        return view('livewire.campaign-workspace', [
            'workspace' => $workspace,
            'products' => $pack ? collect() : $this->scopedProducts()
                ->with(['brand', 'mediaAssets'])->orderBy('name')->get(),
            'briefProduct' => ! $pack && $this->productId ? $this->workspaceProduct($this->productId)->load('brand') : null,
            'contentItems' => $pack && ContentLibrary::available() ? $pack->contentItems()->with('latestVersion')->orderBy('id')->get() : null,
            'pack' => $pack,
            'processJobUrl' => $pack?->latestGenerationJob && config('campaigns.processing_mode') === 'request'
                ? URL::temporarySignedRoute('campaign-jobs.process', now()->addMinutes(30), ['generationJob' => $pack->latestGenerationJob])
                : null,
            'includedRegenerationsRemaining' => $pack ? app(SectionRegenerator::class)->includedRemaining($pack) : 3,
            'reviewFeaturesAvailable' => $reviewFeaturesAvailable,
            'bannerStudioAvailable' => $bannerStudioAvailable,
            'bannerProductImage' => $pack?->product?->mediaAssets()
                ->where('type', 'image')
                ->where('metadata->origin', 'product_page')
                ->oldest()
                ->first(),
            'bannerCreditBalance' => $workspace->creditBalance(),
            'bannerProcessUrl' => $nextBannerCreative && config('campaigns.processing_mode') === 'request'
                ? URL::temporarySignedRoute('banner-creatives.process', now()->addMinutes(30), ['bannerCreative' => $nextBannerCreative])
                : null,
            'nextBannerCreative' => $nextBannerCreative,
            'displayContent' => $displayContent,
            'qaIssues' => $qaIssues,
            'shares' => $pack && $reviewFeaturesAvailable
                ? CampaignPackShare::query()->where('campaign_pack_id', $pack->id)->latest()->get()
                : collect(),
        ])
            ->layout('components.layouts.app');
    }
}
