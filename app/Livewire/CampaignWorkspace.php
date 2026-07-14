<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\BannerCreative;
use App\Models\Brand;
use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\CampaignPackShare;
use App\Models\CampaignPackVersion;
use App\Models\CampaignPackVersionComment;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\BannerBatchCreator;
use App\Services\BannerJobDispatcher;
use App\Services\CampaignJobDispatcher;
use App\Services\CampaignPackContentNormalizer;
use App\Services\CampaignPackSafetyValidator;
use App\Services\ProductImageImporter;
use App\Services\ProductPageFetcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class CampaignWorkspace extends Component
{
    use InteractsWithWorkspace;
    use WithFileUploads;

    public int $step = 1;

    public string $brandName = '';

    public string $brandWebsite = '';

    public string $productName = '';

    public string $productPrice = '';

    public string $productSummary = '';

    public string $productUrl = '';

    public string $loadedProductUrl = '';

    public bool $productDetailsLoaded = false;

    public string $sourceUrl = '';

    public string $analysisMode = 'standard';

    public string $regenerationSection = 'ranked_angles';

    public ?int $selectedVersion = null;

    public $bannerLogo = null;

    public string $bannerPrimaryColor = '#F4B942';

    public ?int $brandId = null;

    public ?int $productId = null;

    public ?int $packId = null;

    public string $commentBody = '';

    public string $commentSection = '';

    public string $reviewNote = '';

    public ?string $shareUrl = null;

    public function mount(?CampaignPack $pack = null): void
    {
        if (! $pack?->exists) {
            return;
        }

        abort_unless($pack->product->brand->workspace_id === $this->currentWorkspace()->id, 404);
        $this->packId = $pack->id;
        $this->productId = $pack->product_id;
        $this->brandId = $pack->product->brand_id;
        $this->bannerPrimaryColor = $pack->product->brand->primary_color ?: '#F4B942';
        $this->step = 4;
    }

    public function useBrand(): void
    {
        $data = $this->validate(['brandId' => ['required', 'integer']]);
        Brand::query()
            ->where('workspace_id', $this->currentWorkspace()->id)
            ->findOrFail($data['brandId']);

        $this->step = 2;
    }

    public function saveBrand(): void
    {
        $data = $this->validate([
            'brandName' => ['required', 'string', 'max:120'],
            'brandWebsite' => ['nullable', 'url', 'max:255'],
        ]);

        $workspace = $this->currentWorkspace();
        if ($workspace->brands()->count() >= config('campaigns.brand_limit')) {
            throw ValidationException::withMessages(['brandName' => 'This workspace has reached its beta brand limit.']);
        }
        $brand = $workspace->brands()->create([
            'name' => $data['brandName'],
            'website' => $data['brandWebsite'] ?: null,
        ]);

        $this->brandId = $brand->id;
        $this->step = 2;
    }

    public function loadProductFromUrl(ProductPageFetcher $fetcher): void
    {
        $data = $this->validate([
            'productUrl' => ['required', 'url:http,https', 'max:2000'],
        ]);

        try {
            $page = $fetcher->fetch($data['productUrl']);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'productUrl' => 'We could not read product details from that URL. Check that the page is public and try again.',
            ]);
        }

        $truth = $page['product_truth'] ?? [];
        $name = trim((string) ($truth['name'] ?? $page['title'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'productUrl' => 'We could not find a product name on that page.',
            ]);
        }

        $resolvedUrl = (string) ($page['canonical_url'] ?? $page['url'] ?? $data['productUrl']);
        $price = trim(implode(' ', array_filter([
            $truth['currency'] ?? null,
            $truth['price'] ?? null,
        ], fn ($value) => $value !== null && $value !== '')));

        $this->productName = mb_substr($name, 0, 160);
        $this->productPrice = mb_substr($price, 0, 40);
        $this->productSummary = mb_substr(trim((string) ($truth['description'] ?? $page['description'] ?? '')), 0, 1000);
        $this->productUrl = $resolvedUrl;
        $this->loadedProductUrl = $resolvedUrl;
        $this->sourceUrl = $resolvedUrl;
        $this->productDetailsLoaded = true;
    }

    public function saveProduct(ProductPageFetcher $fetcher, ProductImageImporter $imageImporter): void
    {
        abort_unless($this->brandId, 422);
        $data = $this->validate([
            'productUrl' => ['required', 'url:http,https', 'max:2000'],
            'productName' => ['required', 'string', 'max:160'],
            'productPrice' => ['nullable', 'string', 'max:40'],
            'productSummary' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($this->loadedProductUrl === '' || ! hash_equals($this->loadedProductUrl, $data['productUrl'])) {
            throw ValidationException::withMessages([
                'productUrl' => 'Analyze this product page before continuing.',
            ]);
        }

        $brand = Brand::query()
            ->where('workspace_id', $this->currentWorkspace()->id)
            ->findOrFail($this->brandId);

        try {
            $page = $fetcher->fetch($data['productUrl']);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'productUrl' => 'We could not capture Product Truth from that URL. Check that the page is public and try again.',
            ]);
        }

        [$product, $source] = DB::transaction(function () use ($brand, $data, $page): array {
            $product = Product::create([
                'brand_id' => $brand->id,
                'name' => $data['productName'],
                'price' => $data['productPrice'] ?: null,
                'summary' => $data['productSummary'] ?: null,
            ]);
            $source = SourceSnapshot::create([
                'product_id' => $product->id,
                'url' => $page['url'],
                'title' => $page['title'],
                'canonical_url' => $page['canonical_url'],
                'content_hash' => $page['content_hash'],
                'status' => 'ready',
                'extracted_content' => $page['content'],
                'extracted_truth' => $page['product_truth'],
                'fetched_at' => now(),
            ]);

            return [$product, $source];
        });

        $image = $imageImporter->importFirst($product, $source, $page['images'] ?? []);
        if ($image) {
            $this->audit('product_image_auto_imported', $image, null, [
                'product_id' => $product->id,
                'source_url' => data_get($image->metadata, 'source_url'),
            ]);
        }

        $this->productId = $product->id;
        $this->sourceUrl = $page['canonical_url'];
        $this->step = 3;
    }

    public function generatePack(CampaignJobDispatcher $dispatcher): void
    {
        abort_unless($this->productId, 422);
        $data = $this->validate([
            'sourceUrl' => ['required', 'url:http,https', 'max:2000'],
            'analysisMode' => ['required', 'in:standard,deep'],
        ]);
        $workspace = $this->currentWorkspace();
        $creditCost = $data['analysisMode'] === 'deep' ? 3 : 1;

        [$pack, $generationJob] = DB::transaction(function () use ($data, $workspace, $creditCost): array {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            if ($lockedWorkspace->creditBalance() < $creditCost) {
                throw ValidationException::withMessages(['sourceUrl' => 'This workspace does not have enough pack credits.']);
            }

            $product = Product::query()
                ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->findOrFail($this->productId);
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
                'name' => "{$product->name} Campaign Pack",
                'status' => 'queued',
                'analysis_mode' => $data['analysisMode'],
                'credit_cost' => $creditCost,
                'current_version' => 0,
                'estimated_cost' => 0,
            ]);

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
                'description' => ucfirst($data['analysisMode']).' campaign pack generation',
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
                'description' => 'Campaign pack generation retry',
            ]);
            $lockedPack->update(['status' => 'queued']);

            return $job;
        });

        $dispatcher->dispatch($job->id);
    }

    public function regenerateSection(CampaignJobDispatcher $dispatcher): void
    {
        $data = $this->validate([
            'regenerationSection' => ['required', 'in:positioning,ranked_angles,creative_routes,offers'],
        ]);
        $workspace = $this->currentWorkspace();
        $pack = CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->findOrFail($this->packId);
        abort_unless($pack->status === 'approved' && $pack->current_version > 0, 422);

        $job = DB::transaction(function () use ($workspace, $pack, $data): CampaignGenerationJob {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            $lockedPack = CampaignPack::query()->lockForUpdate()->findOrFail($pack->id);
            abort_unless($lockedPack->status === 'approved' && $lockedPack->current_version > 0, 422);
            abort_if(
                $lockedPack->generationJobs()->whereIn('status', ['queued', 'processing', 'retrying'])->exists(),
                422,
                'A generation job is already running.',
            );

            $includedUsed = $lockedPack->generationJobs()
                ->whereNotNull('section')
                ->where('status', 'completed')
                ->where('created_at', '<=', $lockedPack->created_at->copy()->addDay())
                ->count();
            $included = now()->lte($lockedPack->created_at->copy()->addDay()) && $includedUsed < 3;
            $creditCost = $included ? 0 : 1;
            if ($creditCost > 0 && $lockedWorkspace->creditBalance() < $creditCost) {
                throw ValidationException::withMessages(['regenerationSection' => 'This workspace does not have enough credits for another regeneration.']);
            }

            $job = CampaignGenerationJob::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $lockedPack->id,
                'source_snapshot_id' => $lockedPack->source_snapshot_id,
                'analysis_mode' => $lockedPack->analysis_mode,
                'section' => $data['regenerationSection'],
                'base_version' => $lockedPack->current_version,
                'credit_cost' => $creditCost,
            ]);

            if ($creditCost > 0) {
                $workspace->credits()->create([
                    'campaign_pack_id' => $lockedPack->id,
                    'campaign_generation_job_id' => $job->id,
                    'amount' => -$creditCost,
                    'event' => 'section_regeneration',
                    'description' => 'Regenerated '.$data['regenerationSection'].' section',
                    'metadata' => ['section' => $data['regenerationSection']],
                ]);
            }

            return $job;
        });

        $dispatcher->dispatch($job->id);
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
    }

    public function approveVersion(): void
    {
        $this->ensureOwner();
        $version = $this->selectedPackVersion();
        abort_unless(in_array($version->review_status, ['draft', 'review']), 422);
        app(CampaignPackSafetyValidator::class)->assertApprovable($version, $version->campaignPack->sourceSnapshot);
        $version->update([
            'review_status' => 'approved',
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $this->reviewNote ?: null,
        ]);
        $version->campaignPack->update(['status' => 'approved']);
        $this->audit('campaign_pack_version_approved', $version, $this->reviewNote ?: null);
        $this->reviewNote = '';
    }

    public function rejectVersion(): void
    {
        $this->ensureOwner();
        $data = $this->validate(['reviewNote' => ['required', 'string', 'max:2000']]);
        $version = $this->selectedPackVersion();
        abort_unless(in_array($version->review_status, ['draft', 'review']), 422);
        $version->update(['review_status' => 'rejected', 'reviewed_by_user_id' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $data['reviewNote']]);
        $version->campaignPack->update(['status' => 'rejected']);
        $this->audit('campaign_pack_version_rejected', $version, $data['reviewNote']);
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
        $this->commentBody = '';
        $this->commentSection = '';
    }

    public function approveSource(): void
    {
        $this->ensureOwner();
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

    private function workspacePack(): CampaignPack
    {
        return CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id))
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
        abort_unless($this->currentWorkspace()->users()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists(), 403);
    }

    private function audit(string $event, object $subject, ?string $reason = null, array $metadata = []): void
    {
        $this->currentWorkspace()->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => $event, 'subject_type' => $subject::class,
            'subject_id' => $subject->id, 'reason' => $reason, 'metadata' => $metadata,
        ]);
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
        $pack = $this->packId
            ? CampaignPack::query()
                ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->with([
                    'product.brand',
                    'sourceSnapshot',
                    $reviewFeaturesAvailable ? 'versions.comments.user' : 'versions',
                    'latestGenerationJob',
                    'bannerGenerationBatches.creatives.campaignPackVersion',
                ])
                ->findOrFail($this->packId)
            : null;

        if (
            $pack?->latestGenerationJob?->status === 'processing'
            && config('campaigns.processing_mode') === 'request'
            && $pack->latestGenerationJob->updated_at->lt(now()->subMinutes(10))
        ) {
            $pack->latestGenerationJob->update(['status' => 'retrying', 'phase' => 'retry_wait']);
            $pack->load('latestGenerationJob');
        }

        if ($pack && Schema::hasTable('banner_creatives')) {
            BannerCreative::query()
                ->where('campaign_pack_id', $pack->id)
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(10))
                ->update(['status' => 'retrying']);
            $pack->load('bannerGenerationBatches.creatives.campaignPackVersion');
        }

        $nextBannerCreative = $pack && Schema::hasTable('banner_creatives')
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
            'brands' => $workspace->brands()->orderBy('name')->get(),
            'pack' => $pack,
            'processJobUrl' => $pack?->latestGenerationJob && config('campaigns.processing_mode') === 'request'
                ? URL::temporarySignedRoute('campaign-jobs.process', now()->addMinutes(30), ['generationJob' => $pack->latestGenerationJob])
                : null,
            'includedRegenerationsRemaining' => $pack
                ? max(0, 3 - $pack->generationJobs()->whereNotNull('section')->where('status', 'completed')->where('created_at', '<=', $pack->created_at->copy()->addDay())->count())
                : 3,
            'reviewFeaturesAvailable' => $reviewFeaturesAvailable,
            'bannerStudioAvailable' => Schema::hasTable('banner_generation_batches') && config('campaigns.banners.enabled'),
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
