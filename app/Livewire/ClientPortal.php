<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Page;
use App\Models\Product;
use App\Services\ContentLibrary;
use App\Support\ClientHub;
use App\Support\Membership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Where a client account lands: work for their one brand that is in review, approved deliverables and live pages.
 * Team members open the same page as a read-only preview of what a client of a brand sees.
 * Clients never see drafts, costs, product knowledge or other brands.
 */
class ClientPortal extends Component
{
    use InteractsWithWorkspace;

    /** Brand a team member previews. Ignored for clients, who only ever see their own brand. */
    #[Url(as: 'brand')]
    public ?int $previewBrand = null;

    /** Product whose client hub is open. */
    #[Url(as: 'product')]
    public ?int $hubProduct = null;

    /** @var array<int, string> item id => comment */
    public array $comments = [];

    public ?string $flash = null;

    public function approve(int $itemId, ContentLibrary $library): void
    {
        $item = $this->reviewItem($itemId);
        $version = $item->latestVersion;
        try {
            $library->approve($version, auth()->user(), null, $this->comments[$itemId] ?? null);
        } catch (ValidationException $exception) {
            $this->addError("decision.{$itemId}", collect($exception->errors())->flatten()->first());

            return;
        }
        unset($this->comments[$itemId]);
        $this->flash = "You approved {$item->title} version {$version->version}. Nothing was published.";
    }

    public function requestChanges(int $itemId, ContentLibrary $library): void
    {
        $item = $this->reviewItem($itemId);
        $this->validate(["comments.{$itemId}" => ['required', 'string', 'max:2000']], ["comments.{$itemId}.required" => 'Tell the team what to change.']);
        $version = $item->latestVersion;
        try {
            $library->requestChanges($version, auth()->user(), $this->comments[$itemId]);
        } catch (ValidationException $exception) {
            $this->addError("decision.{$itemId}", collect($exception->errors())->flatten()->first());

            return;
        }
        unset($this->comments[$itemId]);
        $this->flash = "Your notes on {$item->title} version {$version->version} were sent to {$this->currentWorkspace()->name}.";
    }

    public function render()
    {
        $membership = $this->membership();
        $workspace = $membership->workspace;
        $brands = $membership->isClient() ? collect() : $membership->scopeBrands($workspace->brands()->getQuery(), 'id')->orderBy('name')->get(['id', 'name']);
        $brand = $this->brand($membership, $brands);
        $items = $brand ? $this->items($brand) : collect();
        $hubProducts = $brand ? $this->hubProducts($brand) : collect();
        $openProduct = $this->hubProduct ? $hubProducts->firstWhere('id', $this->hubProduct) : null;
        $hub = $openProduct ? ClientHub::build($openProduct, ClientHub::latestApprovedVersion($openProduct), [
            'media' => fn ($asset) => route('portal.hub-media', [$openProduct->id, $asset]),
            'creative' => fn ($creative) => route('portal.hub-creative', [$openProduct->id, $creative]),
            'export' => fn ($type) => route('portal.hub-export', [$openProduct->id, $type]),
        ]) : null;

        return view('livewire.client-portal', [
            'membership' => $membership,
            'workspace' => $workspace,
            'brand' => $brand,
            'brands' => $brands,
            'preview' => ! $membership->isClient(),
            'pending' => $items->where('status', 'review')->values(),
            'approved' => $items->where('status', 'approved')->values(),
            'pages' => $brand ? $this->livePages($brand) : collect(),
            'workspaces' => auth()->user()->workspaces()->orderBy('name')->get(),
            'available' => ContentLibrary::available(),
            'hubProducts' => $hubProducts,
            'openProduct' => $openProduct,
            'hub' => $hub,
        ])->layout('components.layouts.app')->title('Client portal');
    }

    private function membership(): Membership
    {
        $membership = Membership::of(auth()->user(), $this->currentWorkspace());
        abort_unless($membership, 404);

        return $membership;
    }

    private function brand(Membership $membership, Collection $brands): ?Brand
    {
        if ($membership->isClient()) {
            return $membership->brandId ? $membership->workspace->brands()->find($membership->brandId) : null;
        }
        $id = $brands->firstWhere('id', $this->previewBrand)?->id ?? $brands->first()?->id;

        return $id ? $membership->workspace->brands()->find($id) : null;
    }

    /** @return Collection<int, ContentItem> */
    private function items(Brand $brand): Collection
    {
        if (! ContentLibrary::available()) {
            return collect();
        }

        return ContentItem::query()
            ->where('workspace_id', $brand->workspace_id)
            ->whereHas('product', fn ($query) => $query->where('brand_id', $brand->id))
            ->whereIn('status', ['review', 'approved'])
            // Clients see what clients wrote, never the team's internal notes.
            ->with(['latestVersion', 'product', 'variationOf', 'versions.comments' => fn ($query) => $query->where('author_role', 'client')->orderBy('id')])
            ->orderBy('product_id')->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Product> products of the brand that have an approved launch pack, so a client hub to open */
    private function hubProducts(Brand $brand): Collection
    {
        return Product::query()
            ->where('brand_id', $brand->id)
            ->whereHas('campaignPacks.versions', fn ($query) => $query->where('review_status', 'approved'))
            ->with(['brand', 'resourceLinks', 'mediaAssets'])
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Page> */
    private function livePages(Brand $brand): Collection
    {
        if (! Schema::hasTable('pages')) {
            return collect();
        }

        return Page::query()
            ->where('workspace_id', $brand->workspace_id)
            ->whereHas('product', fn ($query) => $query->where('brand_id', $brand->id))
            ->whereNotNull('live_version')->whereNotNull('remote_url')
            ->with('product')
            ->orderBy('name')
            ->get();
    }

    /** Only a client decides from the portal; the team decides in the review queue. */
    private function reviewItem(int $itemId): ContentItem
    {
        $membership = $this->membership();
        abort_unless($membership->isClient() && $membership->brandId && ContentLibrary::available(), 403);
        $item = ContentItem::query()
            ->where('workspace_id', $membership->workspace->id)
            ->whereHas('product', fn ($query) => $query->where('brand_id', $membership->brandId))
            ->where('status', 'review')
            ->with('latestVersion')
            ->find($itemId);
        abort_unless($item && $item->latestVersion?->status === 'review', 404);

        return $item;
    }
}
