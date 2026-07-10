<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class CampaignWorkspace extends Component
{
    use InteractsWithWorkspace;

    public int $step = 1;

    public string $brandName = '';

    public string $brandWebsite = '';

    public string $productName = '';

    public string $productPrice = '';

    public string $productSummary = '';

    public string $sourceUrl = '';

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

    public function generatePack(MockCampaignPackGenerator $generator): void
    {
        abort_unless($this->productId, 422);
        $data = $this->validate(['sourceUrl' => ['required', 'url', 'max:2000']]);

        $pack = DB::transaction(function () use ($data, $generator): CampaignPack {
            $product = Product::query()
                ->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id))
                ->findOrFail($this->productId);
            $source = SourceSnapshot::create([
                'product_id' => $product->id,
                'url' => $data['sourceUrl'],
                'content_hash' => hash('sha256', $data['sourceUrl']),
                'extracted_truth' => [
                    'name' => $product->name,
                    'price' => $product->price,
                    'source_url' => $data['sourceUrl'],
                ],
            ]);

            $pack = CampaignPack::create([
                'product_id' => $product->id,
                'source_snapshot_id' => $source->id,
                'name' => "{$product->name} Campaign Pack",
                'estimated_cost' => 0.0180,
            ]);

            $pack->versions()->create([
                'content' => $generator->generate($product, $source),
                'evidence' => [[
                    'claim' => 'Product identity and supplied details',
                    'source' => $data['sourceUrl'],
                    'status' => 'source-linked',
                ]],
                'compliance_flags' => [],
            ]);

            return $pack;
        });

        $this->packId = $pack->id;
        $this->step = 4;
    }

    public function startAnother(): void
    {
        $this->reset(['brandName', 'brandWebsite', 'productName', 'productPrice', 'productSummary', 'sourceUrl', 'brandId', 'productId', 'packId']);
        $this->step = 1;
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $pack = $this->packId
            ? CampaignPack::query()
                ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->with(['product.brand', 'sourceSnapshot', 'versions'])
                ->findOrFail($this->packId)
            : null;

        return view('livewire.campaign-workspace', [
            'workspace' => $workspace,
            'brands' => $workspace->brands()->orderBy('name')->get(),
            'pack' => $pack,
        ])
            ->layout('components.layouts.app');
    }
}
