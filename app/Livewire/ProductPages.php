<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\ContentItem;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Product;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PageImages;
use App\Services\Pages\PagePublisher;
use App\Services\Pages\PageRenderer;
use App\Support\Membership;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The product's Pages tab: sections bound to approved content, a live preview, and publishing. */
class ProductPages extends Component
{
    use InteractsWithWorkspace;

    #[Locked]
    public int $productId;

    #[Url(as: 'page')]
    public ?int $pageId = null;

    public string $name = '';

    public string $slug = '';

    public string $headline = '';

    /** @var array<int, array{label: string, url: string, text: string}> CTA fields by section id */
    public array $cta = [];

    public ?string $flash = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        abort_unless($this->product(), 404);
        $this->loadPage();
    }

    public function startPage(PageBuilder $builder): void
    {
        $page = $builder->start($this->product(), auth()->user(), $this->pages()->isEmpty() ? null : 'Page '.($this->pages()->count() + 1));
        $this->pageId = $page->id;
        $this->loadPage();
        $this->flash = 'Page draft v1 created. Sections use approved content where it exists.';
    }

    public function showPage(int $id): void
    {
        $this->pageId = $this->page($id)->id;
        $this->flash = null;
        $this->resetValidation();
        $this->loadPage();
    }

    public function saveDetails(PageBuilder $builder): void
    {
        $page = $this->page();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'headline' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ], ['slug.regex' => 'Use lowercase letters, numbers and dashes.'], ['slug' => 'address']);
        if ($page->name === $data['name'] && $page->headline === $data['headline'] && $page->slug === $data['slug']) {
            return;
        }
        $builder->edit($page, fn (Page $locked) => $locked->update($data));
        $this->flash = 'Page saved.';
    }

    public function bind(int $sectionId, string $itemId, PageBuilder $builder): void
    {
        $builder->bind($this->page(), $this->section($sectionId), $itemId === '' ? null : (int) $itemId);
        $this->flash = null;
    }

    public function saveCta(int $sectionId, PageBuilder $builder): void
    {
        $section = $this->section($sectionId);
        abort_unless($section->kind === 'cta', 404);
        $this->validate([
            "cta.{$sectionId}.label" => ['required', 'string', 'max:40'],
            "cta.{$sectionId}.url" => ['required', 'url:http,https', 'max:500'],
            "cta.{$sectionId}.text" => ['nullable', 'string', 'max:300'],
        ], [], ["cta.{$sectionId}.label" => 'button label', "cta.{$sectionId}.url" => 'button link', "cta.{$sectionId}.text" => 'text']);
        $settings = array_map(fn ($value) => trim((string) $value), $this->cta[$sectionId]);
        if (($section->settings ?? []) == $settings) {
            return;
        }
        $builder->edit($this->page(), fn () => $section->update(['settings' => $settings]));
        $this->flash = 'Call to action saved.';
    }

    public function addSection(string $kind, PageBuilder $builder): void
    {
        $builder->addSection($this->page(), $kind);
        $this->loadPage();
    }

    public function removeSection(int $sectionId, PageBuilder $builder): void
    {
        $builder->removeSection($this->page(), $this->section($sectionId));
    }

    public function move(int $sectionId, int $direction, PageBuilder $builder): void
    {
        $builder->move($this->page(), $this->section($sectionId), $direction);
    }

    public function chooseStore(string $storeId, PageBuilder $builder): void
    {
        abort_unless($this->isOwner(), 403);
        $page = $this->page();
        $store = $storeId === '' ? null : $builder->stores($this->product())->firstWhere('id', (int) $storeId);
        abort_if($storeId !== '' && ! $store, 404);
        if ($page->isBusy()) {
            throw ValidationException::withMessages(['publish' => 'Wait for the current publish to finish before changing the store.']);
        }
        if ($store?->id !== $page->store_connection_id && ($page->remote_id || $page->live_version)) {
            // A different store means a different page there; the next publish creates it.
            $page->update(['store_connection_id' => $store?->id, 'remote_id' => null, 'remote_url' => null, 'remote_store' => null, 'live_version' => null, 'publish_state' => 'idle', 'verified_at' => null, 'last_error' => null]);
        } else {
            $page->update(['store_connection_id' => $store?->id]);
        }
    }

    public function publish(PagePublisher $publisher): void
    {
        $attempt = $publisher->publish($this->page(), auth()->user());
        $this->flash = match ($attempt->status) {
            'live' => "v{$attempt->pageVersion->version} is live. The page address serves it.",
            'verifying' => "The store accepted v{$attempt->pageVersion->version}. Checking the page address…",
            default => null,
        };
    }

    public function rollback(int $version, PagePublisher $publisher): void
    {
        $attempt = $publisher->rollback($this->page(), auth()->user(), $version);
        $this->flash = $attempt->status === 'live' ? "The live page is back on v{$version}. Your draft is kept." : null;
    }

    /** Polled while a publish waits for the page address to serve it. */
    public function verifyPending(PagePublisher $publisher): void
    {
        $page = $this->page();
        if ($page->publish_state === 'verifying') {
            $attempt = $publisher->verify($page);
            if ($attempt?->status === 'live') {
                $this->flash = "v{$attempt->pageVersion->version} is live. The page address serves it.";
            }
        }
    }

    public function render(PageBuilder $builder, PageRenderer $renderer, PageImages $images)
    {
        $product = $this->product();
        $page = $this->pageId ? $this->page() : null;
        $data = ['product' => $product, 'page' => $page, 'pages' => $this->pages(), 'isOwner' => $this->isOwner(), 'kinds' => PageSection::KINDS];

        if ($page) {
            $snapshot = $builder->snapshot($page);
            $imageUrl = fn (array $ref) => $images->previewUrl($ref, $product);
            $sections = $page->sections()->with('contentItem')->get();
            $items = ContentItem::query()->where('product_id', $product->id)->orderBy('title')->get(['id', 'title', 'type', 'status', 'current_version']);
            $liveVersions = $page->attempts()->where('status', 'live')->with('pageVersion')->get()->pluck('pageVersion.version')->unique()->sortDesc()->values();
            $data += [
                'sections' => $sections,
                'preview' => collect($snapshot['sections'])->mapWithKeys(fn (array $section) => [$section['id'] => $renderer->section($section, $snapshot, $imageUrl)]),
                'items' => $items,
                'blockers' => $builder->blockers($page),
                'stores' => $builder->stores($product),
                'store' => $page->storeConnection,
                'attempts' => $page->attempts()->with(['pageVersion', 'user'])->latest('id')->limit(5)->get(),
                'rollbackTo' => $liveVersions->first(fn (int $version) => $version !== $page->live_version && $version < ($page->live_version ?? PHP_INT_MAX)),
                'frozen' => $page->draftIsFrozen(),
            ];
        }

        return view('livewire.product-pages', $data);
    }

    private function loadPage(): void
    {
        $page = $this->pageId ? Page::query()->where('product_id', $this->productId)->find($this->pageId) : $this->pages()->first();
        $this->pageId = $page?->id;
        if (! $page) {
            return;
        }
        $this->name = $page->name;
        $this->slug = $page->slug;
        $this->headline = $page->headline;
        $this->cta = $page->sections()->where('kind', 'cta')->get()
            ->mapWithKeys(fn (PageSection $section) => [$section->id => [
                'label' => (string) ($section->settings['label'] ?? ''), 'url' => (string) ($section->settings['url'] ?? ''), 'text' => (string) ($section->settings['text'] ?? ''),
            ]])->all();
    }

    private function product(): ?Product
    {
        $query = Product::query()->whereKey($this->productId)
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));

        return Membership::of(auth()->user(), $this->currentWorkspace())?->scopeBrands($query)->with('brand')->first();
    }

    private function pages()
    {
        return Page::query()->where('product_id', $this->productId)->orderBy('id')->get();
    }

    private function page(?int $id = null): Page
    {
        $page = Page::query()->where('product_id', $this->productId)->find($id ?? $this->pageId);
        abort_unless($page && $this->product(), 404);

        return $page;
    }

    private function section(int $id): PageSection
    {
        $section = PageSection::query()->where('page_id', $this->pageId)->find($id);
        abort_unless($section, 404);

        return $section;
    }

    private function isOwner(): bool
    {
        return (bool) Membership::of(auth()->user(), $this->currentWorkspace())?->canPublish();
    }
}
