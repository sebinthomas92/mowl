<?php

namespace App\Services\Pages;

use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\StoreConnection;
use App\Models\User;
use App\Services\ContentLibrary;
use App\Services\ProductKnowledge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Page drafts: sections bound to content items, the checks that block publishing, and the frozen
 * snapshot a publish sends to the store.
 */
class PageBuilder
{
    /** Facts the specs table shows, in order, when they are confirmed. */
    public const SPEC_FACTS = ['price', 'availability', 'benefits', 'differentiators'];

    /** Content item sections each kind prefers when a draft is started. */
    private const PREFERRED = ['message' => 'positioning', 'offer' => 'offers', 'benefits' => 'ranked_angles'];

    /** Production runs migrations as a separate step; the Pages tab explains itself until the tables exist. */
    public static function available(): bool
    {
        $container = app();
        if ($container->bound('pages.available')) {
            return $container->make('pages.available');
        }
        $available = ContentLibrary::available() && Schema::hasTable('pages') && Schema::hasTable('publish_attempts');
        if ($available && DB::getDriverName() === 'pgsql') {
            try {
                $available = (bool) DB::scalar("select has_table_privilege(current_user, 'pages', 'SELECT') and has_table_privilege(current_user, 'publish_attempts', 'SELECT')");
            } catch (QueryException) {
                $available = false;
            }
        }
        $container->instance('pages.available', $available);

        return $available;
    }

    /** A new draft with the default sections, bound to approved content where it exists. */
    public function start(Product $product, User $actor, ?string $name = null): Page
    {
        $product->loadMissing('brand');
        $approved = $product->contentItems()->where('status', 'approved')->orderByDesc('updated_at')->orderByDesc('id')->get();
        $name = trim((string) $name) !== '' ? trim((string) $name) : 'Launch page';
        $slug = Str::slug($product->name.' '.($name === 'Launch page' ? 'launch' : $name)) ?: 'page';

        return DB::transaction(function () use ($product, $actor, $approved, $name, $slug): Page {
            $page = Page::create([
                'workspace_id' => $product->brand->workspace_id, 'product_id' => $product->id,
                'store_connection_id' => $this->defaultStore($product)?->id,
                'name' => mb_substr($name, 0, 120), 'slug' => mb_substr($slug, 0, 120), 'headline' => mb_substr($product->name, 0, 200),
                'draft_version' => 1, 'publish_state' => 'idle', 'created_by_user_id' => $actor->id,
            ]);
            $position = 0;
            foreach (['hero', 'message', 'offer', 'benefits', 'specs', 'cta'] as $kind) {
                $item = match ($kind) {
                    'hero' => $approved->first(fn (ContentItem $item) => $item->type === 'image'),
                    'specs', 'cta' => null,
                    default => $approved->first(fn (ContentItem $item) => $item->section === self::PREFERRED[$kind]),
                };
                $page->sections()->create([
                    'position' => ++$position, 'kind' => $kind, 'title' => PageSection::KINDS[$kind][0],
                    'content_item_id' => $item?->id, 'settings' => $this->defaultSettings($kind, $product),
                ]);
            }

            return $page;
        });
    }

    public function defaultSettings(string $kind, Product $product): ?array
    {
        if ($kind === 'cta') {
            return ['label' => 'Shop now', 'url' => $this->productUrl($product)];
        }

        return null;
    }

    /** Stores the page can publish to: the product's brand store first, then workspace-wide ones. */
    public function stores(Product $product): Collection
    {
        $product->loadMissing('brand');

        return StoreConnection::query()
            ->where('workspace_id', $product->brand->workspace_id)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $product->brand_id))
            ->orderByRaw('case when brand_id is null then 1 else 0 end')->orderBy('name')
            ->get();
    }

    public function defaultStore(Product $product): ?StoreConnection
    {
        return $this->stores($product)->first(fn (StoreConnection $store) => $store->isConnected());
    }

    /** Applies an edit to the draft. Editing a published draft starts the next draft version. */
    public function edit(Page $page, callable $change): void
    {
        DB::transaction(function () use ($page, $change): void {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->publish_state === 'publishing') {
                throw ValidationException::withMessages(['page' => 'Wait for publishing to finish before editing.']);
            }
            if ($page->draftIsFrozen()) {
                $page->draft_version++;
                if ($page->publish_state === 'failed') {
                    $page->publish_state = $page->live_version ? 'live' : 'idle';
                    $page->last_error = null;
                }
                $page->save();
            }
            $change($page);
            $page->touch();
        });
    }

    public function bind(Page $page, PageSection $section, ?int $itemId): void
    {
        abort_unless($section->page_id === $page->id, 404);
        $item = null;
        if ($itemId) {
            $item = ContentItem::query()->where('product_id', $page->product_id)->find($itemId);
            if (! $item || ($section->accepts() && $item->type !== $section->accepts())) {
                throw ValidationException::withMessages(['page' => 'That content cannot go in this section.']);
            }
        }
        $this->edit($page, fn () => $section->update(['content_item_id' => $item?->id]));
    }

    public function addSection(Page $page, string $kind): void
    {
        abort_unless(array_key_exists($kind, PageSection::KINDS), 422);
        $this->edit($page, function () use ($page, $kind): void {
            $page->sections()->create([
                'position' => (int) $page->sections()->max('position') + 1, 'kind' => $kind,
                'title' => PageSection::KINDS[$kind][0], 'settings' => $this->defaultSettings($kind, $page->product),
            ]);
        });
    }

    public function removeSection(Page $page, PageSection $section): void
    {
        abort_unless($section->page_id === $page->id, 404);
        $this->edit($page, fn () => $section->delete());
    }

    public function move(Page $page, PageSection $section, int $direction): void
    {
        abort_unless($section->page_id === $page->id, 404);
        $this->edit($page, function () use ($page, $section, $direction): void {
            $sections = $page->sections()->get()->values();
            $index = $sections->search(fn (PageSection $entry) => $entry->id === $section->id);
            $swap = $sections->get($index + ($direction < 0 ? -1 : 1));
            if ($index === false || ! $swap) {
                return;
            }
            $ordered = $sections->all();
            [$ordered[$index], $ordered[$index + ($direction < 0 ? -1 : 1)]] = [$swap, $sections[$index]];
            foreach (array_values($ordered) as $position => $entry) {
                $entry->update(['position' => $position + 1]);
            }
        });
    }

    /**
     * What stops this draft from being published.
     *
     * @return list<string>
     */
    public function blockers(Page $page): array
    {
        $sections = $page->sections()->with('contentItem')->get();
        if ($sections->isEmpty()) {
            return ['The page has no sections.'];
        }
        $blockers = [];
        foreach ($sections as $section) {
            $item = $section->contentItem;
            if ($item && $item->status !== 'approved') {
                $blockers[] = "{$section->title} uses {$item->title} v{$item->current_version}, which is not approved.";
            }
            if ($section->kind === 'cta' && ! $this->safeUrl($section->settings['url'] ?? null)) {
                $blockers[] = "{$section->title} needs a button link that starts with https:// or http://.";
            }
        }

        return $blockers;
    }

    /** The draft as it would be published: section content pinned to exact approved versions. */
    public function snapshot(Page $page): array
    {
        $page->loadMissing('product.brand');
        $product = $page->product;
        $facts = $this->specFacts($product);
        $sections = [];

        foreach ($page->sections()->with(['contentItem.latestVersion', 'contentItem.bannerCreative'])->get() as $section) {
            $item = $section->contentItem;
            $version = $item?->latestVersion;
            $entry = [
                'id' => $section->id, 'kind' => $section->kind, 'title' => $section->title, 'settings' => $section->settings ?? [],
                'item' => $item && $version ? [
                    'id' => $item->id, 'title' => $item->title, 'type' => $item->type, 'status' => $item->status,
                    'section' => $item->section ?? $item->variationOf?->section, 'version' => $version->version,
                    'content_version_id' => $version->id, 'body' => $version->body,
                ] : null,
                'image' => null,
            ];
            if ($section->kind === 'hero') {
                $entry['image'] = $this->imageRef($item, $product);
                $entry['summary'] = (string) $product->summary;
            }
            if ($section->kind === 'specs') {
                $entry['facts'] = $facts;
            }
            $sections[] = $entry;
        }

        return [
            'page_id' => $page->id, 'name' => $page->name, 'headline' => $page->headline,
            'product' => ['id' => $product->id, 'name' => $product->name, 'brand' => $product->brand->name],
            'sections' => $sections,
        ];
    }

    /** @return list<array{label: string, value: string}> */
    public function specFacts(Product $product): array
    {
        if (! ProductKnowledge::available()) {
            return array_values(array_filter([$product->price ? ['label' => 'Price', 'value' => (string) $product->price] : null]));
        }
        $facts = $product->facts()->whereIn('key', self::SPEC_FACTS)->where('status', 'ok')->get()->keyBy('key');

        return collect(self::SPEC_FACTS)
            ->map(fn (string $key) => $facts->get($key))
            ->filter(fn (?ProductFact $fact) => $fact && filled($fact->confirmed_value))
            ->map(fn (ProductFact $fact) => ['label' => ProductKnowledge::FACTS[$fact->key]['label'], 'value' => (string) $fact->confirmed_value])
            ->values()->all();
    }

    /** A banner bound to the hero, else the product's first imported image. */
    private function imageRef(?ContentItem $item, Product $product): ?array
    {
        $creative = $item?->bannerCreative;
        if ($creative && $creative->status === 'completed' && $creative->output_path) {
            return ['key' => "banner-{$creative->id}", 'kind' => 'banner', 'id' => $creative->id, 'alt' => (string) ($creative->headline ?: $product->name)];
        }
        $asset = $product->mediaAssets()->where('mime_type', 'like', 'image/%')->where('status', '!=', 'failed')->orderBy('id')->first();

        return $asset instanceof MediaAsset ? ['key' => "media-{$asset->id}", 'kind' => 'media', 'id' => $asset->id, 'alt' => $product->name] : null;
    }

    private function productUrl(Product $product): ?string
    {
        $snapshot = $product->sourceSnapshots()->where('status', 'ready')->latest('id')->first();

        return $snapshot?->canonical_url ?: $snapshot?->url;
    }

    public function safeUrl(?string $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
