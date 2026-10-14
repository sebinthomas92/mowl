<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\CampaignPackVersion;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\SourceSnapshot;
use App\Services\Campaigns\CampaignPlanner;
use App\Services\ContentLibrary;
use App\Services\Pages\PageBuilder;
use App\Services\ProductHubContentAdapter;
use App\Services\ProductKnowledge;
use App\Services\ProductPageFetcher;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class ProductHub extends Component
{
    use InteractsWithWorkspace;

    public Product $product;

    public array $resourceInputs = [];

    public ?string $shareUrl = null;

    public const TABS = ['overview' => 'Overview', 'knowledge' => 'Knowledge', 'content' => 'Content', 'pages' => 'Pages', 'campaigns' => 'Campaigns', 'results' => 'Results', 'packs' => 'Launch packs', 'hub' => 'Client hub'];

    #[Url]
    public string $tab = 'overview';

    public ?string $editing = null;

    public string $factValue = '';

    private const RESOURCE_LABELS = [
        'product_page' => 'Product page',
        'brand_guide' => 'Brand guide',
        'master_drive' => 'Master Drive folder',
        'meta_drive' => 'Meta Ads folder',
        'google_ads_drive' => 'Google Ads folder',
        'email_sms_drive' => 'Email & SMS folder',
        'organic_social_drive' => 'Organic social folder',
    ];

    public function mount(Product $product): void
    {
        $workspace = $this->currentWorkspace();
        abort_unless($product->brand()->where('workspace_id', $workspace->id)->exists(), 404);
        $this->product = $product;
        $this->resourceInputs = array_fill_keys(array_keys(self::RESOURCE_LABELS), '');
        foreach ($product->resourceLinks as $link) {
            $this->resourceInputs[$link->kind] = $link->url;
        }
        $share = $product->hubShares()->whereNull('revoked_at')->latest()->first();
        if ($share?->isActive()) {
            $this->shareUrl = route('product-hubs.share', $share->token);
        }
    }

    public function saveResources(): void
    {
        $this->authorizeManager();
        $rules = [];
        foreach (array_keys(self::RESOURCE_LABELS) as $kind) {
            $rules["resourceInputs.{$kind}"] = ['nullable', 'url:http,https', 'max:2048'];
        }
        $validated = $this->validate($rules);
        $workspace = $this->product->brand->workspace;

        foreach (self::RESOURCE_LABELS as $kind => $label) {
            $url = trim((string) data_get($validated, "resourceInputs.{$kind}"));
            if ($url === '') {
                $this->product->resourceLinks()->where('kind', $kind)->delete();
            } else {
                $this->product->resourceLinks()->updateOrCreate(['kind' => $kind], [
                    'workspace_id' => $workspace->id,
                    'label' => $label,
                    'url' => $url,
                ]);
            }
        }

        session()->flash('resource-status', 'Resource links saved.');
    }

    public function createShare(): void
    {
        $this->authorizeManager();
        abort_unless($this->latestApprovedVersion(), 422);
        $this->product->hubShares()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $share = $this->product->hubShares()->create([
            'workspace_id' => $this->product->brand->workspace_id,
            'created_by_user_id' => auth()->id(),
            'token' => hash('sha256', Str::random(64)),
        ]);
        $this->shareUrl = route('product-hubs.share', $share->token);
    }

    public function revokeShare(): void
    {
        $this->authorizeManager();
        $this->product->hubShares()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->shareUrl = null;
    }

    public function editFact(string $key): void
    {
        $fact = $this->fact($key);
        $this->editing = $fact->key;
        $this->factValue = (string) $fact->confirmed_value;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->factValue = '';
        $this->resetValidation();
    }

    public function saveFact(ProductKnowledge $knowledge): void
    {
        abort_unless($this->editing, 422);
        $fact = $this->fact($this->editing);
        $max = ProductKnowledge::FACTS[$fact->key]['max'];
        $required = $fact->key === 'name' ? 'required' : 'nullable';
        $this->validate(['factValue' => [$required, 'string', "max:{$max}"]], [], ['factValue' => strtolower(ProductKnowledge::FACTS[$fact->key]['label'])]);

        $before = $fact->confirmed_value;
        $knowledge->confirm($fact, $this->factValue, auth()->user());
        $this->auditFact('product_fact_confirmed', $fact, ['before' => $before, 'after' => $fact->confirmed_value]);
        $this->cancelEdit();
    }

    public function acceptSource(string $key, ProductKnowledge $knowledge): void
    {
        $fact = $this->fact($key);
        $before = $fact->confirmed_value;
        $knowledge->acceptSource($fact, auth()->user());
        $this->auditFact('product_fact_source_accepted', $fact, ['before' => $before, 'after' => $fact->confirmed_value]);
    }

    public function keepConfirmed(string $key, ProductKnowledge $knowledge): void
    {
        $fact = $this->fact($key);
        $knowledge->keepConfirmed($fact, auth()->user());
        $this->auditFact('product_fact_kept', $fact, ['kept' => $fact->confirmed_value, 'source' => $fact->source_value]);
    }

    public function excludeFact(string $key, ProductKnowledge $knowledge): void
    {
        $fact = $this->fact($key);
        $knowledge->exclude($fact);
        $this->auditFact('product_fact_excluded', $fact);
    }

    public function restoreFact(string $key, ProductKnowledge $knowledge): void
    {
        $fact = $this->fact($key);
        $knowledge->restore($fact);
        $this->auditFact('product_fact_restored', $fact);
    }

    public function refreshSource(ProductPageFetcher $fetcher, ProductKnowledge $knowledge): void
    {
        $this->authorizeManager();
        abort_unless(ProductKnowledge::available(), 503);
        $previous = $this->latestSource();
        abort_unless($previous, 422);

        try {
            $page = $fetcher->fetch($previous->canonical_url ?: $previous->url);
        } catch (Throwable $exception) {
            report($exception);
            session()->flash('knowledge-error', 'We could not read the product page. Check that it is public and try again.');

            return;
        }

        $snapshot = SourceSnapshot::create([
            'product_id' => $this->product->id,
            'type' => $previous->type,
            'url' => $page['url'],
            'title' => $page['title'] ?? null,
            'canonical_url' => $page['canonical_url'] ?? null,
            'content_hash' => $page['content_hash'],
            'status' => 'ready',
            'extracted_content' => $page['content'] ?? null,
            'extracted_truth' => $page['product_truth'] ?? [],
            'fetched_at' => now(),
            'refreshed_from_snapshot_id' => $previous->id,
        ]);
        $conflicts = $knowledge->syncFromSource($this->product, $snapshot);
        $this->auditFact('product_source_refreshed', $snapshot, ['conflicts' => $conflicts]);

        session()->flash('knowledge-status', $conflicts
            ? trans_choice('The source changed. :count fact needs your decision.|The source changed. :count facts need your decision.', count($conflicts))
            : 'Source checked. Nothing changed.');
    }

    public function render(ProductHubContentAdapter $adapter)
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
        $workspace = $this->currentWorkspace();
        $this->product->load(['brand', 'resourceLinks', 'mediaAssets', 'campaignPacks.versions']);
        $version = $this->latestApprovedVersion();
        $content = $version ? $adapter->adapt($this->product, $version) : null;
        $sections = $this->sections();
        $resourceLabels = self::RESOURCE_LABELS;
        $canManage = $this->canManage();

        $knowledgeReady = ProductKnowledge::available();
        $facts = $knowledgeReady ? app(ProductKnowledge::class)->facts($this->product) : collect();
        $source = $this->latestSource();
        $brandAudience = $knowledgeReady ? (app(ProductKnowledge::class)->brandProfile($this->product)['default_audience'] ?? null) : null;
        $packs = $this->product->campaignPacks->sortByDesc('id')->values();
        $tabs = self::TABS;
        $contentItems = ContentLibrary::available() ? $this->product->contentItems()->get(['id', 'title', 'status', 'current_version', 'type']) : null;
        $livePage = PageBuilder::available() ? $this->product->pages()->whereNotNull('live_version')->latest('verified_at')->first() : null;
        $campaigns = CampaignPlanner::available() ? $this->product->campaigns()->withCount('results')->orderByDesc('id')->get() : null;

        return view('livewire.product-hub', compact('workspace', 'version', 'content', 'sections', 'resourceLabels', 'canManage', 'knowledgeReady', 'facts', 'source', 'packs', 'tabs', 'brandAudience', 'contentItems', 'livePage', 'campaigns'))
            ->layout('components.layouts.app');
    }

    private function latestApprovedVersion(): ?CampaignPackVersion
    {
        return CampaignPackVersion::query()
            ->whereHas('campaignPack', fn ($query) => $query->where('product_id', $this->product->id))
            ->where('review_status', 'approved')
            ->with(['campaignPack.sourceSnapshot', 'bannerCreatives'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')->first();
    }

    private function sections(): array
    {
        return [
            'overview' => 'Overview', 'product_details' => 'Product details', 'key_messaging' => 'Key messaging',
            'meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads', 'email_sms' => 'Email & SMS',
            'organic_social' => 'Organic social', 'asset_links' => 'Asset links', 'campaign_history' => 'Campaign history',
        ];
    }

    private function fact(string $key): ProductFact
    {
        $this->authorizeManager();
        abort_unless(ProductKnowledge::available() && array_key_exists($key, ProductKnowledge::FACTS), 404);

        return $this->product->facts()->where('key', $key)->firstOrFail();
    }

    private function latestSource(): ?SourceSnapshot
    {
        return $this->product->sourceSnapshots()->where('status', 'ready')->latest('id')->first();
    }

    private function auditFact(string $event, object $subject, array $metadata = []): void
    {
        $this->product->brand->workspace->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => $event, 'subject_type' => $subject::class,
            'subject_id' => $subject->id, 'metadata' => ['product_id' => $this->product->id, 'key' => $subject->key ?? null] + $metadata,
        ]);
    }

    private function canManage(): bool
    {
        return $this->product->brand->workspace->users()->whereKey(auth()->id())->exists();
    }

    private function authorizeManager(): void
    {
        abort_unless($this->canManage(), 403);
    }
}
