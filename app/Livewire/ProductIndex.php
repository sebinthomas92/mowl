<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Product;
use App\Services\ContentLibrary;
use App\Services\ProductKnowledge;
use App\Support\Membership;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductIndex extends Component
{
    use InteractsWithWorkspace;

    /** Products whose source page was last read longer ago than this count as stale. */
    public const STALE_DAYS = 14;

    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $q = '';

    /** active, attention or archived */
    #[Url]
    public string $show = 'active';

    /** list or grid */
    #[Url]
    public string $view = 'list';

    public ?string $notice = null;

    public function archive(int $id): void
    {
        $product = $this->manageableProduct($id);
        $product->forceFill(['archived_at' => now()])->save();
        $this->notice = $product->name.' is archived. Its facts, launch packs and approvals are kept.';
    }

    public function restore(int $id): void
    {
        $product = $this->manageableProduct($id);
        $product->forceFill(['archived_at' => null])->save();
        $this->notice = $product->name.' is back in your products.';
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'brand', 'show');
    }

    private function manageableProduct(int $id): Product
    {
        abort_unless(Product::archiveAvailable(), 404);
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);
        abort_unless($membership?->canCreate(), 403);
        $product = $membership->scopeBrands(Product::query())
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->find($id);
        abort_unless($product, 404);

        return $product;
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);
        $archiveAvailable = Product::archiveAvailable();
        $show = in_array($this->show, ['active', 'attention', 'archived'], true) ? $this->show : 'active';
        if ($show === 'archived' && ! $archiveAvailable) {
            $show = 'active';
        }
        $search = trim(mb_strtolower($this->q));
        $all = $membership->scopeBrands(Product::query())
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($this->brand, fn ($query) => $query->where('brand_id', $this->brand))
            ->with([
                'brand', 'campaignPacks.versions:id,campaign_pack_id,review_status',
                'hubShares' => fn ($query) => $query->whereNull('revoked_at'),
                'mediaAssets' => fn ($query) => $query->where('mime_type', 'like', 'image/%')->where('status', '!=', 'failed')->orderBy('id'),
            ])
            ->withMax(['sourceSnapshots as source_checked_at' => fn ($query) => $query->where('status', 'ready')], 'created_at')
            ->withCount('campaignPacks')
            ->when(ContentLibrary::available(), fn ($query) => $query->withCount([
                'contentItems as in_review_count' => fn ($items) => $items->where('status', 'review'),
                'contentItems as changes_count' => fn ($items) => $items->where('status', 'changes'),
            ]))
            ->when(ProductKnowledge::available(), fn ($query) => $query->withCount([
                'facts as conflicts_count' => fn ($facts) => $facts->where('status', 'conflict'),
            ]))
            ->latest()
            ->get();

        $archivedCount = $archiveAvailable ? $all->filter->isArchived()->count() : 0;
        $active = $all->reject->isArchived()->values();
        $attentionCount = $active->filter(fn (Product $product) => $this->needsAttention($product))->count();
        $products = match ($show) {
            'archived' => $all->filter->isArchived()->values(),
            'attention' => $active->filter(fn (Product $product) => $this->needsAttention($product))->values(),
            default => $active,
        };
        if ($search !== '') {
            $products = $products->filter(fn (Product $product) => str_contains(mb_strtolower($product->name.' '.$product->brand->name), $search))->values();
        }
        $filtered = $search !== '' || $show !== 'active';
        $view = $this->view === 'grid' ? 'grid' : 'list';

        $brands = $membership->scopeBrands($workspace->brands()->getQuery(), 'id')->orderBy('name')->get(['id', 'name']);
        $canCreate = $membership->canCreate();

        $gettingStarted = $filtered ? null : $this->gettingStarted($active, $canCreate, $workspace->isAgency());

        return view('livewire.product-index', compact(
            'workspace', 'products', 'brands', 'canCreate', 'gettingStarted', 'archiveAvailable', 'show', 'view',
            'archivedCount', 'attentionCount', 'filtered', 'active'
        ))
            ->layout('components.layouts.app')->title('Products');
    }

    /** Something on the product is waiting for the team: changed facts, failed or unreviewed work, or a stale source. */
    public function needsAttention(Product $product): bool
    {
        $latest = $product->campaignPacks->sortByDesc('id')->first();

        return ($product->conflicts_count ?? 0) > 0
            || ($product->changes_count ?? 0) > 0
            || ($product->in_review_count ?? 0) > 0
            || $latest?->status === 'failed'
            || $this->isStale($product);
    }

    public function isStale(Product $product): bool
    {
        return $product->source_checked_at !== null
            && Carbon::parse($product->source_checked_at)->lt(now()->subDays(self::STALE_DAYS));
    }

    /**
     * The path from an empty workspace to a first shared launch pack. Shown until someone has
     * done it once, then never again. Null when there is nothing left to guide.
     */
    private function gettingStarted($products, bool $canCreate, bool $agency): ?array
    {
        if ($this->brand) {
            return null;
        }
        $first = $products->sortBy('id')->first();
        $withPack = $products->first(fn (Product $product) => $product->campaign_packs_count > 0);
        $approved = $products->first(fn (Product $product) => $product->campaignPacks->contains(fn ($pack) => $pack->versions->contains('review_status', 'approved')));
        $shared = $products->contains(fn (Product $product) => $product->hubShares->isNotEmpty());
        $packOf = fn (?Product $product) => $product?->campaignPacks->sortByDesc('id')->first();

        $steps = [
            ['title' => 'Add a product', 'hint' => 'Paste its page address and we read the name, price and details for you to check.', 'done' => (bool) $first,
                'label' => $canCreate ? 'Add product' : null, 'url' => route('products.create')],
            ['title' => 'Write a launch pack', 'hint' => 'Positioning, ad angles, three video scripts and channel copy, written only from the product\'s facts. 1 credit, about two minutes.', 'done' => (bool) $withPack,
                'label' => $canCreate && $first ? 'Create launch pack' : null, 'url' => $first ? route('campaign-packs.create', ['product' => $first->id]) : null],
            ['title' => 'Check and approve it', 'hint' => 'Approve the product facts, then each output. Approving never publishes anything.', 'done' => (bool) $approved,
                'label' => $withPack ? 'Open launch pack' : null, 'url' => $withPack ? route('campaign-packs.show', $packOf($withPack)) : null],
            ['title' => $agency ? 'Make banners and share with your client' : 'Make banners and share', 'hint' => $agency ? 'Your client gets one link with the approved copy and images.' : 'Download images and copy lines, or send one link to anyone helping you launch.', 'done' => $shared,
                'label' => $approved ? 'Open Share' : null, 'url' => $approved ? route('products.show', [$approved, 'tab' => 'hub']) : null],
        ];

        return collect($steps)->contains('done', false) && ! $shared ? $steps : null;
    }
}
