<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\ContentClientLink;
use App\Models\ContentItem;
use App\Models\ContentVersion;
use App\Models\Product;
use App\Services\ContentLibrary;
use App\Services\Pages\PageBuilder;
use App\Services\SectionRegenerator;
use App\Support\Membership;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Content items with their versions, comments and where they are used. Shown on a product's
 * Content tab (one product) and on the Library page (every product in the workspace).
 */
class ContentBrowser extends Component
{
    use InteractsWithWorkspace;

    /** Keys that hold ids, ranks or timing, never prose a person should retype. */
    public const LOCKED_KEYS = ['id', 'shot_id', 'status', 'supporting_claim_ids', 'claim_validation_status', 'angle_rank', 'rank', 'voiceover', 'captions', 'shot_plan', 'final_url', 'total_duration_seconds'];

    #[Locked]
    public ?int $productId = null;

    #[Url(as: 'type')]
    public string $type = 'all';

    #[Url(as: 'status')]
    public string $status = 'all';

    #[Url(as: 'item')]
    public ?int $itemId = null;

    #[Url(as: 'q')]
    public string $search = '';

    /** An edit field to open and highlight, set by links from the launch pack QA list. */
    #[Url(as: 'field')]
    public ?string $focusField = null;

    public string $detailTab = 'preview';

    public array $picked = [];

    public array $fields = [];

    public string $commentBody = '';

    public ?string $flash = null;

    public function mount(?int $productId = null): void
    {
        $this->productId = $productId;
        if ($productId) {
            abort_unless($this->product(), 404);
        }
        app(ContentLibrary::class)->syncWorkspace($this->currentWorkspace(), $this->product());
        if ($this->itemId && $this->focusField && $this->scopedItems()->whereKey($this->itemId)->exists() && $this->membership()?->canCreate()) {
            $this->showTab('edit');
        }
    }

    public function select(int $id): void
    {
        $this->itemId = $this->item($id)->id;
        $this->focusField = null;
        $this->detailTab = 'preview';
        $this->commentBody = '';
        $this->flash = null;
        $this->resetValidation();
    }

    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, ['preview', 'edit', 'versions', 'comments', 'used'], true), 404);
        $this->detailTab = $tab;
        if ($tab === 'edit') {
            $this->fields = $this->editableFields($this->item()->latestVersion->body);
        }
    }

    public function filter(string $key, string $value): void
    {
        if ($key === 'type' && ($value === 'all' || array_key_exists($value, ContentItem::TYPES))) {
            $this->type = $value;
        }
        if ($key === 'status' && ($value === 'all' || array_key_exists($value, ContentItem::STATUSES))) {
            $this->status = $value;
        }
    }

    public function clearFilters(): void
    {
        $this->type = 'all';
        $this->status = 'all';
        $this->search = '';
    }

    public function saveDraft(ContentLibrary $library): void
    {
        $item = $this->item();
        $paths = array_keys($this->editableFields($item->latestVersion->body));
        $this->validate(collect($paths)->mapWithKeys(fn ($key) => ["fields.{$key}" => ['nullable', 'string', 'max:4000']])->all());
        $values = collect($this->fields)->only($paths)->mapWithKeys(fn ($value, $key) => [str_replace('__', '.', $key) => (string) $value])->all();
        $library->saveDraft($item->latestVersion, $values);
        $this->flash = 'Draft saved.';
    }

    public function submit(ContentLibrary $library): void
    {
        $item = $this->item();
        $library->submit($item->latestVersion, auth()->user());
        $this->flash = "{$item->title} v{$item->current_version} submitted for review.";
        $this->detailTab = 'preview';
    }

    public function revise(ContentLibrary $library): void
    {
        $version = $library->revise($this->item(), auth()->user());
        $this->flash = "Revision v{$version->version} started. Edit it, then submit it for review.";
        $this->showTab('edit');
    }

    public function variation(ContentLibrary $library): void
    {
        $copy = $library->variation($this->item(), auth()->user());
        $this->itemId = $copy->id;
        $this->detailTab = 'preview';
        $this->flash = 'Variation created as a new draft. The original stays as it is.';
    }

    public function regenerate(SectionRegenerator $regenerator): void
    {
        $item = $this->item();
        $section = $this->regenerableSection($item);
        abort_unless($section, 422);
        $regenerator->request($this->currentWorkspace(), $item->campaignPack, $section, 'decision');
        $this->flash = 'Regenerating. A new draft version appears here when it is ready.';
    }

    public function addComment(ContentLibrary $library): void
    {
        $this->validate(['commentBody' => ['required', 'string', 'max:2000']], [], ['commentBody' => 'comment']);
        $library->comment($this->item()->latestVersion, auth()->user(), $this->commentBody);
        $this->commentBody = '';
    }

    public function bulkSubmit(ContentLibrary $library): void
    {
        $submitted = 0;
        $skipped = 0;
        foreach ($this->scopedItems()->whereIn('id', $this->picked)->get() as $item) {
            if ($item->latestVersion?->status === 'draft') {
                $library->submit($item->latestVersion, auth()->user());
                $submitted++;
            } else {
                $skipped++;
            }
        }
        $this->picked = [];
        $this->flash = trans_choice(':count item submitted for review|:count items submitted for review', $submitted).($skipped ? ", {$skipped} skipped because they are not drafts." : '.');
    }

    /** Older named review links stay listed until they expire, so an owner can still turn one off. New links are made in the Client hub tab. */
    public function revokeClientLink(int $id): void
    {
        abort_unless($this->product() && $this->isOwner(), 403);
        ContentClientLink::query()->where('workspace_id', $this->currentWorkspace()->id)->where('product_id', $this->productId)->whereKey($id)->update(['revoked_at' => now()]);
    }

    public function render(ContentLibrary $library)
    {
        $workspace = $this->currentWorkspace();
        $all = $library->items($workspace, $this->product(), $this->membership());
        $list = $all
            ->when($this->type !== 'all', fn (Collection $items) => $items->where('type', $this->type))
            ->when($this->status !== 'all', fn (Collection $items) => $items->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Collection $items) => $items->filter(fn (ContentItem $item) => str_contains(mb_strtolower($item->title.' '.$item->product->name), mb_strtolower(trim($this->search)))))
            ->values();
        $selected = $list->firstWhere('id', $this->itemId) ?? $list->first();
        $this->itemId = $selected?->id;
        $versions = $selected ? $selected->versions()->with(['comments', 'createdBy', 'reviewedBy'])->orderByDesc('version')->get() : collect();
        $this->picked = array_values(array_intersect(array_map('intval', $this->picked), $list->pluck('id')->all()));

        return view('livewire.content-browser', [
            'all' => $all,
            'list' => $list,
            'selected' => $selected,
            'versions' => $versions,
            'latest' => $versions->first(),
            'editable' => $selected ? $this->editableFields($versions->first()?->body ?? []) : [],
            'regenerable' => $selected ? $this->regenerableSection($selected) : null,
            'packBusy' => $selected?->campaignPack?->generationJobs()->whereIn('status', ['queued', 'processing', 'retrying'])->exists() ?? false,
            'usedIn' => $selected ? $this->usedIn($selected) : [],
            'showProduct' => ! $this->productId,
            'isOwner' => $this->isOwner(),
            'canCreate' => (bool) $this->membership()?->canCreate(),
            'clientLinks' => $this->productId
                ? ContentClientLink::query()->where('workspace_id', $workspace->id)->where('product_id', $this->productId)->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get()
                : collect(),
        ]);
    }

    /** @return array<string, string> encoded dot path => current text */
    private function editableFields(array $body, string $prefix = '', int $depth = 0): array
    {
        $fields = [];
        foreach ($body as $key => $value) {
            if (in_array((string) $key, self::LOCKED_KEYS, true) || $depth > 3) {
                continue;
            }
            $path = $prefix === '' ? (string) $key : "{$prefix}__{$key}";
            if (is_string($value)) {
                $fields[$path] = $value;
            } elseif (is_array($value) && array_is_list($value) && $value !== [] && collect($value)->every(fn ($entry) => is_string($entry))) {
                $fields[$path] = implode("\n", $value);
            } elseif (is_array($value)) {
                $fields += $this->editableFields($value, $path, $depth + 1);
            }
        }

        return $fields;
    }

    private function regenerableSection(ContentItem $item): ?string
    {
        if (! $item->isPackSection() || ! $item->campaignPack) {
            return null;
        }
        $section = explode('.', $item->section)[0];

        return in_array($section, SectionRegenerator::SECTIONS, true) ? $section : null;
    }

    private function usedIn(ContentItem $item): array
    {
        $used = [];
        $pack = $item->campaignPack;
        if ($item->isPackSection() && $pack) {
            $approved = $pack->versions()->where('review_status', 'approved')->orderByDesc('version')->first();
            $used[] = ['icon' => 'stack-2', 'text' => "Launch pack: {$pack->name}", 'url' => route('campaign-packs.show', $pack)];
            if ($approved) {
                $used[] = ['icon' => 'world-share', 'text' => "Client hub and exports use launch pack v{$approved->version}", 'url' => null];
            }
        }
        if ($item->banner_creative_id && $pack) {
            $used[] = ['icon' => 'photo', 'text' => "Banner Studio in {$pack->name}", 'url' => route('campaign-packs.show', $pack).'#banner-studio'];
        }
        if (PageBuilder::available()) {
            foreach ($item->pageSections()->with('page')->get()->unique('page_id') as $section) {
                $page = $section->page;
                $state = $page->live_version ? "live v{$page->live_version}" : 'not published';
                $used[] = ['icon' => 'layout', 'text' => "Page: {$page->name} ({$section->title}, draft v{$page->draft_version}, {$state})", 'url' => route('products.show', [$page->product_id, 'tab' => 'pages', 'page' => $page->id])];
            }
        }
        if ($item->variationOf) {
            $used[] = ['icon' => 'copy', 'text' => "Variation of {$item->variationOf->title}", 'url' => null];
        }

        return $used;
    }

    private function product(): ?Product
    {
        if (! $this->productId) {
            return null;
        }

        $query = Product::query()->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));

        return $this->membership()?->scopeBrands($query)->find($this->productId);
    }

    private function membership(): ?Membership
    {
        return Membership::of(auth()->user(), $this->currentWorkspace());
    }

    private function isOwner(): bool
    {
        return (bool) $this->membership()?->isOwner();
    }

    /** Items of the workspace (or the product) within the member's brand scope. */
    private function scopedItems()
    {
        $query = ContentItem::query()
            ->where('workspace_id', $this->currentWorkspace()->id)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->with('latestVersion');

        return $this->membership()?->scopeThroughProduct($query) ?? $query->whereRaw('1 = 0');
    }

    private function item(?int $id = null): ContentItem
    {
        $item = $this->scopedItems()->with(['campaignPack', 'product'])->find($id ?? $this->itemId);
        abort_unless($item && $item->latestVersion instanceof ContentVersion, 404);

        return $item;
    }
}
