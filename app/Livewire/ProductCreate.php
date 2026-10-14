<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\ProductImageImporter;
use App\Services\ProductKnowledge;
use App\Services\ProductPageFetcher;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Add a product from its page or by hand. Saving only records the product and its confirmed facts;
 * it never starts generation.
 */
class ProductCreate extends Component
{
    use InteractsWithWorkspace;

    /** "url" imports from a product page; "manual" is typed in. */
    public string $mode = 'url';

    /** A brand id, or "new" to create one alongside the product. */
    public string $brandId = '';

    public string $newBrandName = '';

    public string $productUrl = '';

    public string $name = '';

    public string $price = '';

    public string $description = '';

    public string $audience = '';

    public string $benefits = '';

    /** The address the confirmed values below were read from. Save re-reads this exact page. */
    #[Locked]
    public string $importedUrl = '';

    /** What the page said, for the "Source says" column. */
    #[Locked]
    public array $source = [];

    #[Locked]
    public int $imageCount = 0;

    #[Locked]
    public ?int $duplicateId = null;

    public bool $keepBoth = false;

    public function mount(): void
    {
        $brands = $this->scopedBrands()->orderBy('name')->get(['id']);
        $requested = (int) request()->query('brand');
        $this->brandId = (string) ($brands->firstWhere('id', $requested)?->id ?? $brands->first()?->id ?? 'new');
    }

    public function setMode(string $mode): void
    {
        abort_unless(in_array($mode, ['url', 'manual'], true), 422);
        $this->mode = $mode;
        $this->resetErrorBag();
    }

    public function updatedProductUrl(): void
    {
        // A different address invalidates what was read from the previous one.
        if ($this->importedUrl !== '' && $this->normalizedInput() !== $this->importedUrl) {
            $this->reset('importedUrl', 'source', 'imageCount', 'duplicateId', 'keepBoth');
        }
    }

    public function import(ProductPageFetcher $fetcher): void
    {
        $this->productUrl = $this->normalizedInput();
        $this->validate(['productUrl' => ['required', 'url:http,https', 'max:2000']], [
            'productUrl.required' => 'Enter a product page address, such as shop.example/product-name.',
            'productUrl.url' => 'Enter a product page address, such as shop.example/product-name.',
        ]);

        try {
            $page = $fetcher->fetch($this->productUrl);
        } catch (Throwable $exception) {
            report($exception);
            $this->reset('importedUrl', 'source', 'imageCount', 'duplicateId');

            throw ValidationException::withMessages([
                'productUrl' => 'We could not read that page. Check that it is public, or add the product manually.',
            ]);
        }

        $truth = $page['product_truth'] ?? [];
        $source = app(ProductKnowledge::class)->sourceValues($truth);
        $source['name'] ??= trim((string) ($page['title'] ?? '')) ?: null;
        $source['description'] ??= trim((string) ($page['description'] ?? '')) ?: null;
        $source = array_filter($source, fn ($value) => $value !== null);

        $this->source = $source;
        $this->imageCount = count($page['images'] ?? []);
        $this->importedUrl = $this->productUrl;
        $this->name = mb_substr($source['name'] ?? '', 0, 160);
        $this->price = mb_substr($source['price'] ?? '', 0, 40);
        $this->description = mb_substr($source['description'] ?? '', 0, 1000);
        $this->keepBoth = false;
        $this->duplicateId = $this->findDuplicate([$this->productUrl, $page['url'] ?? null, $page['canonical_url'] ?? null])?->id;
    }

    public function save(ProductPageFetcher $fetcher, ProductImageImporter $imageImporter)
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'price' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'audience' => ['nullable', 'string', 'max:500'],
            'benefits' => ['nullable', 'string', 'max:1000'],
            'productUrl' => $this->mode === 'manual' ? ['nullable', 'url:http,https', 'max:2000'] : ['nullable'],
        ], [
            'name.required' => 'Give the product a name so you can find it later.',
        ]);

        $page = null;
        if ($this->mode === 'url') {
            if ($this->importedUrl === '' || $this->normalizedInput() !== $this->importedUrl) {
                throw ValidationException::withMessages(['productUrl' => 'Import this page first, or add the product manually.']);
            }
            if ($this->duplicateId && ! $this->keepBoth) {
                throw ValidationException::withMessages(['productUrl' => 'Another product already uses this address. Open it, or choose Keep both.']);
            }
            try {
                $page = $fetcher->fetch($this->importedUrl);
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages(['productUrl' => 'We could not read that page again. Try once more, or add the product manually.']);
            }
        }

        $brand = $this->resolveBrand();
        $workspace = $this->currentWorkspace();

        [$product, $snapshot] = DB::transaction(function () use ($brand, $data, $page, $workspace): array {
            $product = Product::create([
                'brand_id' => $brand->id,
                'name' => trim($data['name']),
                'price' => filled($data['price']) ? trim($data['price']) : null,
                'summary' => filled($data['description']) ? trim($data['description']) : null,
            ]);
            $snapshot = $page ? SourceSnapshot::create([
                'product_id' => $product->id,
                'url' => $page['url'],
                'title' => $page['title'],
                'canonical_url' => $page['canonical_url'],
                'content_hash' => $page['content_hash'],
                'status' => 'ready',
                'extracted_content' => $page['content'],
                'extracted_truth' => $page['product_truth'],
                'fetched_at' => now(),
            ]) : null;
            if (! $page && filled($data['productUrl'])) {
                $product->resourceLinks()->create([
                    'workspace_id' => $workspace->id, 'kind' => 'product_page', 'label' => 'Product page', 'url' => $data['productUrl'],
                ]);
            }

            return [$product, $snapshot];
        });

        if ($snapshot) {
            try {
                $imageImporter->importFirst($product, $snapshot, $page['images'] ?? []);
            } catch (Throwable $exception) {
                // An image is a nice-to-have; the product is already saved.
                report($exception);
            }
        }

        if (ProductKnowledge::available()) {
            $knowledge = app(ProductKnowledge::class);
            $knowledge->syncFromSource($product, $snapshot);
            $facts = $product->facts()->get()->keyBy('key');
            // Everything on this screen was confirmed by the person saving it. From a page, an emptied
            // price or description is a deliberate choice; by hand, only what was typed counts.
            $confirmed = ['name' => $data['name'], 'price' => $data['price'], 'description' => $data['description'], 'audience' => $data['audience'], 'benefits' => $data['benefits']];
            foreach ($confirmed as $key => $value) {
                $fact = $facts->get($key);
                $explicit = $page && in_array($key, ['price', 'description'], true);
                if ($fact && (filled($value) || $explicit)) {
                    $knowledge->confirm($fact, (string) $value, auth()->user());
                }
            }
        }

        $workspace->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => 'product_created', 'subject_type' => Product::class,
            'subject_id' => $product->id, 'metadata' => ['mode' => $this->mode, 'brand_id' => $brand->id, 'duplicate_of' => $this->keepBoth ? $this->duplicateId : null],
        ]);

        session()->flash('knowledge-status', "{$product->name} saved. Nothing was generated.");

        return $this->redirectRoute('products.show', $product, navigate: false);
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $brands = $this->scopedBrands()->orderBy('name')->get();
        $brand = $brands->firstWhere('id', (int) $this->brandId);

        return view('livewire.product-create', [
            'workspace' => $workspace,
            'brands' => $brands,
            'brand' => $brand,
            'brandLimitReached' => $workspace->brands()->count() >= config('campaigns.brand_limit'),
            'canCreateBrands' => (bool) $this->membership()?->canCreateBrands(),
            'duplicate' => $this->duplicateId ? Product::query()->whereKey($this->duplicateId)->whereIn('brand_id', $brands->pluck('id'))->with('brand')->first() : null,
            'profileAvailable' => Brand::profileAvailable(),
        ])->layout('components.layouts.app');
    }

    private function resolveBrand(): Brand
    {
        $workspace = $this->currentWorkspace();
        if ($this->brandId !== 'new') {
            // Only this workspace's brands can own the product.
            return $this->scopedBrands()->find((int) $this->brandId)
                ?? throw ValidationException::withMessages(['brandId' => 'Pick one of your brands.']);
        }
        if (! $this->membership()?->canCreateBrands()) {
            throw ValidationException::withMessages(['brandId' => 'Pick one of your brands.']);
        }

        $data = $this->validate(['newBrandName' => ['required', 'string', 'max:120']], [
            'newBrandName.required' => 'Name the new brand, or pick an existing one.',
        ]);
        if ($workspace->brands()->count() >= config('campaigns.brand_limit')) {
            throw ValidationException::withMessages(['newBrandName' => 'This workspace has reached its brand limit. Pick an existing brand.']);
        }
        $brand = $workspace->brands()->create(['name' => trim($data['newBrandName'])]);
        $this->brandId = (string) $brand->id;

        return $brand;
    }

    /** People type "shop.example/product"; the fetcher needs a scheme. */
    private function normalizedInput(): string
    {
        $url = trim($this->productUrl);
        if ($url !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    /** A product in this workspace whose source page is one of these addresses. */
    private function findDuplicate(array $urls): ?Product
    {
        $keys = array_values(array_unique(array_filter(array_map(fn ($url) => self::addressKey($url), $urls))));
        if (! $keys) {
            return null;
        }

        $match = SourceSnapshot::query()
            ->whereHas('product.brand', fn ($query) => $this->membership()?->scopeBrands($query->where('workspace_id', $this->currentWorkspace()->id), 'id') ?? $query->whereRaw('1 = 0'))
            ->latest('id')
            ->get(['id', 'product_id', 'url', 'canonical_url'])
            ->first(fn (SourceSnapshot $snapshot) => in_array(self::addressKey($snapshot->url), $keys, true)
                || in_array(self::addressKey($snapshot->canonical_url), $keys, true));

        return $match ? Product::find($match->product_id) : null;
    }

    /** "https://www.Shop.example/item/?a=1#top" and "http://shop.example/item?a=1" are the same page. */
    public static function addressKey(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }
        $parts = parse_url(trim($url));
        if (! isset($parts['host'])) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower($parts['host']));
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.(isset($parts['port']) ? ':'.$parts['port'] : '').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function membership(): ?Membership
    {
        return Membership::of(auth()->user(), $this->currentWorkspace());
    }

    /** Brands the member may add products to. */
    private function scopedBrands()
    {
        $query = $this->currentWorkspace()->brands()->getQuery();

        return $this->membership()?->scopeBrands($query, 'id') ?? $query->whereRaw('1 = 0');
    }
}
