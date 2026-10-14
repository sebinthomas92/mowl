<?php

namespace App\Livewire;

use App\Models\ContentClientLink;
use App\Models\ContentItem;
use App\Services\ContentLibrary;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The page a client opens from their named review link. It shows only items of the linked product
 * that are in review or approved, never drafts, costs or internal comments on other versions.
 */
class ClientReview extends Component
{
    public string $token = '';

    /** @var array<int, string> item id => comment */
    public array $comments = [];

    public ?string $flash = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $link = $this->link();
        $link->forceFill(['last_used_at' => now()])->save();
    }

    public function approve(int $itemId, ContentLibrary $library): void
    {
        $link = $this->link();
        $version = $this->reviewItem($link, $itemId)->latestVersion;
        try {
            $library->approve($version, null, $link->client_name, $this->comments[$itemId] ?? null);
        } catch (ValidationException $exception) {
            $this->addError("decision.{$itemId}", collect($exception->errors())->flatten()->first());

            return;
        }
        unset($this->comments[$itemId]);
        $this->flash = "Thanks, {$version->item->title} v{$version->version} is approved.";
    }

    public function requestChanges(int $itemId, ContentLibrary $library): void
    {
        $link = $this->link();
        $this->validate(["comments.{$itemId}" => ['required', 'string', 'max:2000']], ["comments.{$itemId}.required" => 'Say what should change so the team can act on it.']);
        $version = $this->reviewItem($link, $itemId)->latestVersion;
        $library->requestChanges($version, null, $this->comments[$itemId], $link->client_name);
        unset($this->comments[$itemId]);
        $this->flash = "Thanks, the team has your notes on {$version->item->title} v{$version->version}.";
    }

    public function render()
    {
        $link = $this->link();
        $items = ContentItem::query()
            ->where('product_id', $link->product_id)
            ->whereIn('status', ['review', 'approved'])
            ->with(['latestVersion.comments', 'variationOf'])
            ->orderBy('id')
            ->get();

        return view('livewire.client-review', [
            'link' => $link,
            'product' => $link->product()->with('brand')->first(),
            'pending' => $items->where('status', 'review')->values(),
            'approved' => $items->where('status', 'approved')->values(),
        ])->layout('components.layouts.app');
    }

    private function link(): ContentClientLink
    {
        abort_unless(ContentLibrary::available(), 404);
        $link = ContentClientLink::findActive($this->token);
        abort_unless($link, 404);

        return $link;
    }

    private function reviewItem(ContentClientLink $link, int $itemId): ContentItem
    {
        $item = ContentItem::query()->where('product_id', $link->product_id)->where('status', 'review')->with('latestVersion')->find($itemId);
        abort_unless($item && $item->latestVersion?->status === 'review', 404);

        return $item;
    }
}
