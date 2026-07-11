<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\CampaignJobDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

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

    public string $sourceUrl = '';

    public string $analysisMode = 'standard';

    public string $regenerationSection = 'meta';

    public ?int $selectedVersion = null;

    public array $mediaUploads = [];

    public ?int $brandId = null;

    public ?int $productId = null;

    public ?int $packId = null;

    public function mount(?CampaignPack $pack = null): void
    {
        if (! $pack?->exists) {
            return;
        }

        abort_unless($pack->product->brand->workspace_id === $this->currentWorkspace()->id, 404);
        $this->packId = $pack->id;
        $this->productId = $pack->product_id;
        $this->brandId = $pack->product->brand_id;
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

    public function saveProduct(): void
    {
        abort_unless($this->brandId, 422);
        $data = $this->validate([
            'productName' => ['required', 'string', 'max:160'],
            'productPrice' => ['nullable', 'string', 'max:40'],
            'productSummary' => ['nullable', 'string', 'max:1000'],
        ]);

        $brand = Brand::query()
            ->where('workspace_id', $this->currentWorkspace()->id)
            ->findOrFail($this->brandId);

        $product = Product::create([
            'brand_id' => $brand->id,
            'name' => $data['productName'],
            'price' => $data['productPrice'] ?: null,
            'summary' => $data['productSummary'] ?: null,
        ]);

        $this->productId = $product->id;
        $this->step = 3;
    }

    public function generatePack(CampaignJobDispatcher $dispatcher): void
    {
        abort_unless($this->productId, 422);
        $data = $this->validate([
            'sourceUrl' => ['required', 'url:http,https', 'max:2000'],
            'analysisMode' => ['required', 'in:standard,deep'],
            'mediaUploads' => config('campaigns.media.uploads_enabled') ? ['array', 'max:8'] : ['prohibited'],
            'mediaUploads.*' => config('campaigns.media.uploads_enabled') ? ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm', 'max:102400'] : ['prohibited'],
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
            $source = SourceSnapshot::create([
                'product_id' => $product->id,
                'url' => $data['sourceUrl'],
                'content_hash' => hash('sha256', $data['sourceUrl']),
                'status' => 'pending',
            ]);

            foreach ($this->mediaUploads as $upload) {
                $realPath = $upload->getRealPath();
                $hash = hash_file('sha256', $realPath);
                $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->extension() ?: 'bin');
                $mimeType = $upload->getMimeType();
                $originalName = $upload->getClientOriginalName();
                $size = $upload->getSize();
                $disk = config('campaigns.media.disk');
                $path = $upload->storeAs(
                    "campaign-media/{$workspace->id}/{$product->id}/originals",
                    "{$hash}.{$extension}",
                    $disk,
                );
                MediaAsset::firstOrCreate(
                    ['product_id' => $product->id, 'content_hash' => $hash],
                    [
                        'workspace_id' => $workspace->id,
                        'source_snapshot_id' => $source->id,
                        'type' => str_starts_with($mimeType, 'video/') ? 'video' : 'image',
                        'disk' => $disk,
                        'path' => $path,
                        'original_name' => $originalName,
                        'mime_type' => $mimeType,
                        'size_bytes' => $size,
                    ],
                );
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
            ->with('latestGenerationJob')
            ->findOrFail($this->packId);

        abort_unless($pack->status === 'failed', 422);

        $job = DB::transaction(function () use ($workspace, $pack): CampaignGenerationJob {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            if ($lockedWorkspace->creditBalance() < $pack->credit_cost) {
                throw ValidationException::withMessages(['sourceUrl' => 'This workspace does not have enough pack credits to retry.']);
            }

            $job = CampaignGenerationJob::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $pack->id,
                'source_snapshot_id' => $pack->source_snapshot_id,
                'analysis_mode' => $pack->analysis_mode,
                'credit_cost' => $pack->credit_cost,
            ]);
            $workspace->credits()->create([
                'campaign_pack_id' => $pack->id,
                'amount' => -$pack->credit_cost,
                'event' => 'generation_retry',
                'description' => 'Campaign pack generation retry',
            ]);
            $pack->update(['status' => 'queued']);

            return $job;
        });

        $dispatcher->dispatch($job->id);
    }

    public function regenerateSection(CampaignJobDispatcher $dispatcher): void
    {
        $data = $this->validate([
            'regenerationSection' => ['required', 'in:direction,positioning,meta,hooks,script,captions,shot_log'],
        ]);
        $workspace = $this->currentWorkspace();
        $pack = CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with('latestGenerationJob')
            ->findOrFail($this->packId);
        abort_unless($pack->status === 'approved' && $pack->current_version > 0, 422);
        abort_if(in_array($pack->latestGenerationJob?->status, ['queued', 'processing', 'retrying']), 422, 'A generation job is already running.');

        $includedUsed = $pack->generationJobs()
            ->whereNotNull('section')
            ->where('status', 'completed')
            ->where('created_at', '<=', $pack->created_at->copy()->addDay())
            ->count();
        $included = now()->lte($pack->created_at->copy()->addDay()) && $includedUsed < 3;
        $creditCost = $included ? 0 : 1;

        $job = DB::transaction(function () use ($workspace, $pack, $data, $creditCost): CampaignGenerationJob {
            $lockedWorkspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->id);
            if ($creditCost > 0 && $lockedWorkspace->creditBalance() < $creditCost) {
                throw ValidationException::withMessages(['regenerationSection' => 'This workspace does not have enough credits for another regeneration.']);
            }

            $job = CampaignGenerationJob::create([
                'workspace_id' => $workspace->id,
                'campaign_pack_id' => $pack->id,
                'source_snapshot_id' => $pack->source_snapshot_id,
                'analysis_mode' => $pack->analysis_mode,
                'section' => $data['regenerationSection'],
                'base_version' => $pack->current_version,
                'credit_cost' => $creditCost,
            ]);

            if ($creditCost > 0) {
                $workspace->credits()->create([
                    'campaign_pack_id' => $pack->id,
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
    }

    public function selectVersion(int $version): void
    {
        $pack = CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id))
            ->findOrFail($this->packId);
        abort_unless($pack->versions()->where('version', $version)->exists(), 404);

        $this->selectedVersion = $version;
    }

    public function startAnother(): void
    {
        $this->redirectRoute('campaign-packs.create', navigate: true);
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $pack = $this->packId
            ? CampaignPack::query()
                ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->with(['product.brand', 'sourceSnapshot', 'versions', 'latestGenerationJob'])
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
        ])
            ->layout('components.layouts.app');
    }
}
