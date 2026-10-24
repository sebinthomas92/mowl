<?php

namespace App\Livewire;

use App\Models\ContentClientLink;
use App\Models\ContentItem;
use App\Models\Product;
use App\Models\ProductHubShare;
use App\Models\User;
use App\Services\ContentLibrary;
use App\Support\ClientHub;
use App\Support\Membership;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The one page a client opens from a link, no sign-in: work waiting for their decision, then the client hub
 * with everything approved and ready to use. It looks the same as a product in the client portal.
 *
 * Two kinds of token open it: the client link from the Client hub tab (/shared/products/{token}) and
 * older named review links (/client/{token}). Clients never see drafts, costs or the team's internal notes.
 */
class ClientLink extends Component
{
    #[Locked]
    public string $token = '';

    /** "hub" for a client link, "review" for an older named review link. */
    #[Locked]
    public string $kind = 'hub';

    /** Who is deciding. Fixed for a named review link; typed once on a client link. */
    public string $reviewerName = '';

    /** @var array<int, string> item id => comment */
    public array $comments = [];

    public ?string $flash = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        // Client links store their token; older review links only store its hash.
        $this->kind = ProductHubShare::query()->where('token', $token)->exists() ? 'hub' : 'review';
        $link = $this->link();
        if ($link instanceof ContentClientLink) {
            $link->forceFill(['last_used_at' => now()])->save();
            $this->reviewerName = $link->client_name;
        }
    }

    public function approve(int $itemId, ContentLibrary $library): void
    {
        $name = $this->decider();
        $version = $this->reviewItem($itemId)->latestVersion;
        try {
            $library->approve($version, null, $name, $this->comments[$itemId] ?? null);
        } catch (ValidationException $exception) {
            $this->addError("decision.{$itemId}", collect($exception->errors())->flatten()->first());

            return;
        }
        unset($this->comments[$itemId]);
        $this->flash = "Thanks, you approved {$version->item->title} version {$version->version}. Nothing was published.";
    }

    public function requestChanges(int $itemId, ContentLibrary $library): void
    {
        $name = $this->decider();
        $this->validate(["comments.{$itemId}" => ['required', 'string', 'max:2000']], ["comments.{$itemId}.required" => 'Say what should change so the team can act on it.']);
        $version = $this->reviewItem($itemId)->latestVersion;
        try {
            $library->requestChanges($version, null, $this->comments[$itemId], $name);
        } catch (ValidationException $exception) {
            $this->addError("decision.{$itemId}", collect($exception->errors())->flatten()->first());

            return;
        }
        unset($this->comments[$itemId]);
        $this->flash = "Thanks, the team has your notes on {$version->item->title} version {$version->version}.";
    }

    public function render()
    {
        $link = $this->link();
        $product = $link->product->load(['brand.workspace', 'resourceLinks', 'mediaAssets']);
        $items = $this->items($product);
        $version = ClientHub::latestApprovedVersion($product);
        $hub = $version ? ClientHub::build($product, $version, $this->hubLinks()) : null;
        $imageRoute = $this->kind === 'review' ? 'client.review-banner' : 'product-hubs.shared-item-image';

        return view('livewire.client-link', [
            'product' => $product,
            'workspace' => $product->brand->workspace,
            'link' => $link,
            'pending' => $items->where('status', 'review')->values(),
            'approved' => $items->where('status', 'approved')->values(),
            'hub' => $hub,
            'canDecide' => $this->canDecide($link),
            'imageUrl' => fn (ContentItem $item) => $item->banner_creative_id ? route($imageRoute, [$this->token, $item->id]) : null,
        ])->layout('components.layouts.app')->title($product->name.' · '.$product->brand->name);
    }

    private function link(): ContentClientLink|ProductHubShare
    {
        if ($this->kind === 'review') {
            $link = ContentLibrary::available() ? ContentClientLink::findActive($this->token) : null;
        } else {
            $link = ProductHubShare::query()->where('token', $this->token)->first();
            $link = $link?->isActive() ? $link : null;
        }
        abort_unless($link && $link->product, 404);

        return $link;
    }

    /** @return array{media: callable, creative: callable, export: callable} */
    private function hubLinks(): array
    {
        $prefix = $this->kind === 'review' ? 'client.hub' : 'product-hubs.shared';

        return [
            'media' => fn ($asset) => route("{$prefix}-media", [$this->token, $asset]),
            'creative' => fn ($creative) => route("{$prefix}-creative", [$this->token, $creative]),
            'export' => fn ($type) => route("{$prefix}-export", [$this->token, $type]),
        ];
    }

    /** @return Collection<int, ContentItem> */
    private function items(Product $product): Collection
    {
        if (! ContentLibrary::available()) {
            return collect();
        }

        return ContentItem::query()
            ->where('product_id', $product->id)
            ->whereIn('status', ['review', 'approved'])
            // Clients see what clients wrote, never the team's internal notes.
            ->with(['latestVersion', 'product', 'variationOf', 'versions.comments' => fn ($query) => $query->where('author_role', 'client')->orderBy('id')])
            ->orderBy('id')
            ->get();
    }

    /**
     * A client may only decide when the person who shared the link could approve work themselves (an owner or
     * reviewer), so a link never lets anyone skip the team's own review rules.
     */
    private function canDecide(ContentClientLink|ProductHubShare $link): bool
    {
        return ContentLibrary::available() && self::makerCanApprove($link->created_by_user_id ? User::find($link->created_by_user_id) : null, $link->product);
    }

    /** Whether someone could approve this product's work themselves: a reviewing role with the product's brand in scope. */
    public static function makerCanApprove(?User $maker, Product $product): bool
    {
        $membership = $maker ? Membership::of($maker, $product->brand->workspace_id) : null;

        return (bool) ($membership?->canReview() && $membership->allowsBrand($product->brand_id));
    }

    private function decider(): string
    {
        $link = $this->link();
        abort_unless($this->canDecide($link), 403);
        // A named review link always decides as the person it was made for.
        if ($link instanceof ContentClientLink) {
            return $link->client_name;
        }
        $this->validate(['reviewerName' => ['required', 'string', 'max:120']], ['reviewerName.required' => 'Add your name so the team knows who decided.']);

        return trim($this->reviewerName);
    }

    private function reviewItem(int $itemId): ContentItem
    {
        $item = ContentItem::query()->where('product_id', $this->link()->product_id)->where('status', 'review')->with('latestVersion.item')->find($itemId);
        abort_unless($item && $item->latestVersion?->status === 'review', 404);

        return $item;
    }
}
