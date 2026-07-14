<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\CampaignPackVersion;
use App\Models\Product;
use App\Services\ProductHubContentAdapter;
use Illuminate\Support\Str;
use Livewire\Component;

class ProductHub extends Component
{
    use InteractsWithWorkspace;

    public Product $product;

    public array $resourceInputs = [];

    public ?string $shareUrl = null;

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

    public function render(ProductHubContentAdapter $adapter)
    {
        $workspace = $this->currentWorkspace();
        $this->product->load(['brand', 'resourceLinks', 'mediaAssets', 'campaignPacks.versions']);
        $version = $this->latestApprovedVersion();
        $content = $version ? $adapter->adapt($this->product, $version) : null;
        $sections = $this->sections();
        $resourceLabels = self::RESOURCE_LABELS;
        $canManage = $this->canManage();

        return view('livewire.product-hub', compact('workspace', 'version', 'content', 'sections', 'resourceLabels', 'canManage'))
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

    private function canManage(): bool
    {
        return $this->product->brand->workspace->users()->whereKey(auth()->id())->exists();
    }

    private function authorizeManager(): void
    {
        abort_unless($this->canManage(), 403);
    }
}
