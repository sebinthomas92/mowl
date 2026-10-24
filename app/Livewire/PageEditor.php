<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Page;
use App\Models\Product;
use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\DocumentRenderer;
use App\Services\Pages\Document\PageClaims;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Document\Sections;
use App\Services\Pages\Document\Templates;
use App\Services\Pages\Document\Theme;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PageGenerator;
use App\Services\Pages\PageImages;
use App\Services\Pages\PagePublisher;
use App\Services\Pages\ProductPhotos;
use App\Support\Membership;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The full-screen page editor. The page itself (canvas, section list, settings, undo and autosave)
 * runs in the browser from resources/js/page-editor.js and talks to the renderless actions below; the
 * server renders every preview with the same renderer that publishes, so the canvas is the live page.
 * The publish drawer is rendered here.
 */
class PageEditor extends Component
{
    use InteractsWithWorkspace, WithFileUploads;

    #[Locked]
    public int $productId;

    #[Locked]
    public int $pageId;

    public bool $drawer = false;

    /** An image being uploaded from the image picker. */
    public $upload = null;

    public ?string $flash = null;

    /** What the editor script starts from. Only the first render sends it; later renders leave the editor alone. */
    protected ?array $config = null;

    private ?ProductBrief $brief = null;

    private ?array $imageMap = null;

    private array $imageChecks = [];

    private ?Product $productModel = null;

    public function mount(Product $product, Page $page, PageBuilder $builder)
    {
        abort_unless(PageBuilder::available(), 404);
        $workspace = $this->currentWorkspace();
        abort_unless($page->product_id === $product->id && $product->brand()->where('workspace_id', $workspace->id)->exists(), 404);
        $this->productId = $product->id;
        $this->pageId = $page->id;
        if (! Membership::of(auth()->user(), $workspace)?->canCreate()) {
            return $this->redirectRoute('pages.preview', [$product, $page]);
        }
        if (! $page->hasDocument()) {
            try {
                $page = $builder->convertLegacy($page);
            } catch (ValidationException) {
                return $this->redirectRoute('pages.preview', [$product, $page]);
            }
        }
        // An AI run the server cut off gets its credit back when the page is next opened.
        app(PageGenerator::class)->settleStale($page);
        $this->config = $this->startingConfig($page);
    }

    /** The canvas: the page as it will publish, with editing hooks and hidden sections shown faded. */
    #[Renderless]
    public function preview(mixed $document): string
    {
        $clean = Document::sanitize($document, fn (array $ref) => $this->imageAllowed($ref), true);

        return app(DocumentRenderer::class)->render($clean, ['preview' => true, 'page_id' => $this->pageId], fn (array $ref) => $this->resolveImage($ref));
    }

    /** Autosave. A save made from an older revision is refused instead of overwriting newer work. */
    #[Renderless]
    public function save(mixed $document, int $revision, PageBuilder $builder): array
    {
        $page = $this->page();
        try {
            $result = $builder->saveDocument($page, $document, $revision);
        } catch (ValidationException $exception) {
            return $this->failure($exception);
        }
        $page->refresh();

        return ['ok' => true, 'revision' => $result['revision'], 'document' => $result['document'], 'state' => $this->state($page), 'checks' => $this->checks($page)];
    }

    /** A new section from the library, filled from the product where it can be. */
    #[Renderless]
    public function starter(string $type, mixed $document): ?array
    {
        if (! Sections::exists($type)) {
            return null;
        }

        return Templates::starter($type, $this->brief(), Document::sanitize($document));
    }

    /** A whole new document from a template, for starting over. */
    #[Renderless]
    public function templateDocument(string $key): ?array
    {
        return Templates::exists($key) ? Templates::document($key, $this->brief()) : null;
    }

    /** The AI writes the whole page ($sectionId null) or one section. */
    #[Renderless]
    public function generate(?string $sectionId, string $instruction, int $revision, PageGenerator $generator): array
    {
        $page = $this->page();
        try {
            $result = $generator->write($page, auth()->user(), $sectionId, $instruction, $revision);
        } catch (ValidationException $exception) {
            return $this->failure($exception);
        }
        $page->refresh();

        return [
            'ok' => true, 'revision' => $result['revision'], 'document' => $result['document'], 'state' => $this->state($page),
            'checks' => $this->checks($page), 'ai' => $this->aiState($page),
        ];
    }

    /** An owner confirms a claim the sources do not back up. Re-renders the publish drawer. */
    public function confirmClaim(string $key, int $revision, PageBuilder $builder): array
    {
        $page = $this->page();
        if ($page->document_revision !== $revision) {
            return ['ok' => false, 'conflict' => true, 'message' => 'This page changed in another tab or by the AI. Reload to get the latest version.'];
        }
        try {
            $builder->confirm($page, $key, auth()->user());
        } catch (ValidationException $exception) {
            return $this->failure($exception);
        }
        $page->refresh();

        return ['ok' => true, 'revision' => $page->document_revision, 'document' => $page->document, 'state' => $this->state($page), 'checks' => $this->checks($page)];
    }

    #[Renderless]
    public function saveDetails(string $name, string $slug, PageBuilder $builder): array
    {
        $name = trim($name);
        $slug = trim($slug);
        $validator = validator(compact('name', 'slug'), [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ], ['slug.regex' => 'Use lowercase letters, numbers and dashes.'], ['slug' => 'address', 'name' => 'page title']);
        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors()->toArray()];
        }
        try {
            $builder->saveDetails($this->page(), $name, $slug);
        } catch (ValidationException $exception) {
            return $this->failure($exception);
        }

        return ['ok' => true, 'state' => $this->state($this->page())];
    }

    /** Stores the image uploaded into $upload and returns the image library. */
    #[Renderless]
    public function storeUpload(ProductPhotos $photos): array
    {
        $validator = validator(['upload' => $this->upload], ['upload' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.ProductPhotos::MAX_UPLOAD_KB]], [], ['upload' => 'image']);
        if ($validator->fails()) {
            $this->upload = null;

            return ['ok' => false, 'message' => $validator->errors()->first('upload')];
        }
        try {
            $asset = $photos->storeUpload($this->product(), $this->upload);
        } catch (ValidationException $exception) {
            return $this->failure($exception);
        } finally {
            $this->upload = null;
        }

        return ['ok' => true, 'images' => $this->imageList(true), 'key' => "media-{$asset->id}"];
    }

    /** Brings in more of the product's own photos from its store page. */
    #[Renderless]
    public function importPhotos(ProductPhotos $photos): array
    {
        try {
            $added = $photos->importFromProductPage($this->product());
        } catch (ValidationException $exception) {
            return $this->failure($exception);
        }

        return [
            'ok' => true, 'images' => $this->imageList(true),
            'message' => $added > 0 ? trans_choice('Added :count photo from the product page.|Added :count photos from the product page.', $added) : 'No new photos: the product page photos are already here.',
        ];
    }

    public function openDrawer(): void
    {
        $this->drawer = true;
        $this->resetErrorBag();
    }

    public function closeDrawer(): void
    {
        $this->drawer = false;
        $this->flash = null;
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

    /** $revision is the one the editor shows: a page changed since is not published. */
    public function publish(int $revision, PagePublisher $publisher): void
    {
        $attempt = $publisher->publish($this->page(), auth()->user(), $revision);
        $this->flash = match ($attempt->status) {
            'live' => "v{$attempt->pageVersion->version} is live. The page address serves it.",
            'verifying' => "The store accepted v{$attempt->pageVersion->version}. Checking the page address…",
            default => null,
        };
        $this->dispatch('page-state', state: $this->state($this->page()));
    }

    public function rollback(int $version, PagePublisher $publisher): void
    {
        $attempt = $publisher->rollback($this->page(), auth()->user(), $version);
        $this->flash = $attempt->status === 'live' ? "The live page is back on v{$version}. Your draft is kept." : null;
        $this->dispatch('page-state', state: $this->state($this->page()));
    }

    /** Polled while a publish waits for the page address to serve it. */
    public function verifyPending(PagePublisher $publisher): void
    {
        $page = $this->page();
        if ($page->publish_state !== 'verifying') {
            return;
        }
        $attempt = $publisher->verify($page);
        if ($attempt?->status === 'live') {
            $this->flash = "v{$attempt->pageVersion->version} is live. The page address serves it.";
        }
        $this->dispatch('page-state', state: $this->state($this->page()));
    }

    public function render(PageBuilder $builder)
    {
        $page = $this->page();
        $product = $this->product();
        $data = ['page' => $page, 'product' => $product, 'config' => $this->config, 'isOwner' => $this->isOwner(), 'state' => $this->state($page)];
        if ($this->drawer) {
            $liveVersions = $page->attempts()->where('status', 'live')->with('pageVersion')->get()->pluck('pageVersion.version')->unique()->sortDesc()->values();
            $issues = $builder->issues($page, $this->brief());
            $data += [
                'issues' => $issues,
                'blockers' => PageClaims::blocking($issues),
                'stores' => $builder->stores($product),
                'store' => $page->storeConnection,
                'attempts' => $page->attempts()->with(['pageVersion', 'user'])->latest('id')->limit(6)->get(),
                'rollbackTo' => $liveVersions->first(fn (int $version) => $version !== $page->live_version && $version < ($page->live_version ?? PHP_INT_MAX)),
            ];
        }

        return view('livewire.page-editor', $data)->layout('components.layouts.app')->title($page->name.' · '.$product->name);
    }

    /** @return array<string, mixed> */
    private function startingConfig(Page $page): array
    {
        $product = $this->product();
        $brief = $this->brief();
        $palettes = Theme::PALETTES;
        if ($brand = Theme::brandPalette($brief->brand['palette'] ?? [])) {
            $palettes = ['brand' => $brand] + $palettes;
        }

        return [
            'document' => $page->document,
            'revision' => $page->document_revision,
            'page' => ['id' => $page->id, 'name' => $page->name, 'slug' => $page->slug, 'template' => $page->template],
            'product' => ['name' => $product->name, 'brand' => $product->brand->name, 'price' => $brief->price, 'buyUrl' => $brief->buyUrl],
            'library' => ['sections' => Sections::all(), 'groups' => Sections::GROUPS, 'icons' => Sections::ICONS, 'backgrounds' => Sections::BACKGROUNDS],
            'theme' => [
                'palettes' => $palettes, 'colors' => Theme::COLOR_KEYS, 'fonts' => Theme::FONTS, 'buttons' => Theme::BUTTONS,
                'spacing' => Theme::SPACING, 'widths' => Theme::WIDTHS, 'weights' => Theme::WEIGHTS, 'cases' => Theme::CASES,
            ],
            'templates' => array_map(fn (array $template) => $template + ['theme' => Templates::theme($template['key'], $brief)], Templates::choices()),
            'images' => $this->imageList(),
            'withheldBanners' => count($brief->withheld),
            'limits' => ['sections' => Document::MAX_SECTIONS],
            'ai' => $this->aiState($page),
            'state' => $this->state($page),
            'checks' => $this->checks($page),
            'write' => request()->boolean('write') && ! $page->generations()->exists(),
            'isOwner' => $this->isOwner(),
            'urls' => [
                'back' => route('products.show', [$product, 'tab' => 'pages']),
                'preview' => route('pages.preview', [$product, $page]),
                'billing' => route('billing.index'),
                'knowledge' => route('products.show', [$product, 'tab' => 'knowledge']),
            ],
        ];
    }

    private function aiState(Page $page): array
    {
        $cost = app(PageGenerator::class)->pageCost($page);
        $balance = $this->currentWorkspace()->spendableCredits();

        return [
            'pageCost' => $cost,
            // Brand-scoped teammates learn whether the workspace can pay for a write, not its balance.
            'credits' => $this->canSeeCosts() ? $balance : null,
            'short' => $cost > 0 && $balance < $cost,
            'written' => $page->generations()->where('status', 'completed')->exists(),
        ];
    }

    /** The page's publishing state, for the top bar. */
    private function state(Page $page): array
    {
        $live = $page->publish_state === 'live' && $page->live_version === $page->draft_version;
        $key = $page->publish_state === 'live' && ! $live ? 'idle' : $page->publish_state;
        [$tone, $label] = Page::STATES[$key] ?? ['', $key];
        if ($key === 'idle' && $page->live_version) {
            $label = "Live v{$page->live_version} · draft v{$page->draft_version} not published";
        }

        return [
            'publish' => $page->publish_state, 'tone' => $tone, 'label' => $label, 'draft' => $page->draft_version, 'live' => $page->live_version,
            'frozen' => $page->draftIsFrozen(), 'url' => app(PageBuilder::class)->safeUrl($page->remote_url) ? $page->remote_url : null, 'name' => $page->name, 'slug' => $page->slug,
        ];
    }

    /**
     * What the page's checks found, for marking text on the canvas and counting what needs attention.
     *
     * @return list<array{key: ?string, kind: string, section: ?string, path: ?string, where: string, message: string, confirmed: bool, by: ?string}>
     */
    private function checks(Page $page): array
    {
        return array_map(fn (array $issue) => [
            'key' => $issue['key'], 'kind' => $issue['kind'], 'section' => $issue['section'], 'path' => $issue['path'],
            'where' => $issue['where'], 'message' => $issue['message'], 'confirmed' => (bool) $issue['confirmed'],
            'by' => $issue['confirmed']['name'] ?? null,
        ], app(PageBuilder::class)->issues($page, $this->brief()));
    }

    /**
     * Product photos and banners the picker offers, with URLs the editor can show.
     *
     * @return list<array>
     */
    private function imageList(bool $fresh = false): array
    {
        if ($this->imageMap !== null && ! $fresh) {
            return array_values($this->imageMap);
        }
        $product = $this->product();
        $images = app(PageImages::class);
        $map = [];
        foreach (ProductBrief::images($product) as $image) {
            if ($url = $images->previewUrl($image, $product)) {
                $map[$image['key']] = $image + ['url' => $url];
            }
        }

        return array_values($this->imageMap = $map);
    }

    private function imageAllowed(array $ref): bool
    {
        if ($this->imageMap === null) {
            $this->imageList();
        }

        return $this->imageChecks[$ref['key']] ??= isset($this->imageMap[$ref['key']]) || app(PageImages::class)->find($ref, $this->product()->brand->workspace_id, $this->productId) !== null;
    }

    private function resolveImage(array $ref): ?array
    {
        if ($this->imageMap === null) {
            $this->imageList();
        }
        $known = $this->imageMap[$ref['key']] ?? null;
        if ($known) {
            return ['url' => $known['url'], 'width' => $known['width'] ?? null, 'height' => $known['height'] ?? null];
        }
        $url = app(PageImages::class)->previewUrl($ref, $this->product());

        return $url ? ['url' => $url] : null;
    }

    private function failure(ValidationException $exception): array
    {
        $errors = $exception->errors();

        return ['ok' => false, 'conflict' => isset($errors['revision']), 'message' => (string) collect($errors)->flatten()->first()];
    }

    private function brief(): ProductBrief
    {
        return $this->brief ??= ProductBrief::for($this->product());
    }

    private function product(): Product
    {
        if ($this->productModel) {
            return $this->productModel;
        }
        $workspace = $this->currentWorkspace();
        $query = Product::query()->whereKey($this->productId)->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id));
        $product = Membership::of(auth()->user(), $workspace)?->scopeBrands($query)->with('brand')->first();
        abort_unless($product, 404);

        return $this->productModel = $product;
    }

    /**
     * The page, found through product() so every action checks again that the person is still a
     * member of the workspace with this product in their brand scope.
     */
    private function page(): Page
    {
        $page = $this->product()->pages()->find($this->pageId);
        abort_unless($page, 404);

        return $page;
    }

    private function isOwner(): bool
    {
        return (bool) Membership::of(auth()->user(), $this->currentWorkspace())?->canPublish();
    }

    private function canSeeCosts(): bool
    {
        return (bool) Membership::of(auth()->user(), $this->currentWorkspace())?->canSeeCosts();
    }
}
