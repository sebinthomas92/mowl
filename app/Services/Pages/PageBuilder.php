<?php

namespace App\Services\Pages;

use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentLibrary;
use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\PageClaims;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Document\Templates;
use App\Services\ProductKnowledge;
use App\Support\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Page drafts. A draft is one document (theme and sections, see {@see Document}) saved by the page
 * editor; publishing freezes it into a page version. The checks in {@see PageClaims} decide whether a
 * draft may go out.
 *
 * Pages made before the editor existed have page sections bound to content items instead. They still
 * publish and roll back as they are, and turn into a document the first time they open in the editor.
 */
class PageBuilder
{
    /** Facts the specs table shows, in order, when they are confirmed. */
    public const SPEC_FACTS = ['price', 'availability', 'benefits', 'differentiators'];

    public function __construct(private PageClaims $claims, private PageImages $images) {}

    /** Production runs migrations as a separate step; the Pages tab explains itself until the tables exist. */
    public static function available(): bool
    {
        $container = app();
        if ($container->bound('pages.available')) {
            return $container->make('pages.available');
        }
        $available = ContentLibrary::available() && Schema::hasTable('pages') && Schema::hasTable('publish_attempts')
            && Schema::hasTable('page_generations') && Schema::hasColumn('pages', 'document');
        if ($available && DB::getDriverName() === 'pgsql') {
            try {
                $available = (bool) DB::scalar("select has_table_privilege(current_user, 'pages', 'SELECT') and has_table_privilege(current_user, 'publish_attempts', 'SELECT') and has_table_privilege(current_user, 'page_generations', 'SELECT')");
            } catch (QueryException) {
                $available = false;
            }
        }
        $container->instance('pages.available', $available);

        return $available;
    }

    /**
     * A new page from a template, filled from what the product's sources say. With $forAi the
     * template keeps every section for the AI to write; otherwise sections with nothing true to
     * say are left out.
     */
    public function start(Product $product, User $actor, ?string $name = null, string $template = 'studio', bool $forAi = false): Page
    {
        $product->loadMissing('brand');
        $template = Templates::exists($template) ? $template : 'studio';
        $name = trim((string) $name) !== '' ? trim((string) $name) : 'Launch page';
        $slug = Str::slug($product->name.' '.($name === 'Launch page' ? 'launch' : $name)) ?: 'page';
        $document = Templates::document($template, ProductBrief::for($product), $forAi);

        return Page::create([
            'workspace_id' => $product->brand->workspace_id, 'product_id' => $product->id,
            'store_connection_id' => $this->defaultStore($product)?->id,
            'name' => mb_substr($name, 0, 120), 'slug' => mb_substr($slug, 0, 120), 'headline' => mb_substr($product->name, 0, 200),
            'draft_version' => 1, 'publish_state' => 'idle', 'created_by_user_id' => $actor->id,
            'document' => $document, 'document_revision' => 1, 'template' => $template,
        ]);
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
    public function edit(Page $page, callable $change): mixed
    {
        return DB::transaction(function () use ($page, $change): mixed {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->publish_state === 'publishing') {
                throw ValidationException::withMessages(['page' => 'Wait for publishing to finish before editing.']);
            }
            $this->startNextDraft($page);
            $result = $change($page);
            $page->touch();

            return $result;
        });
    }

    /**
     * Saves the editor's document. A save made against an older revision is refused, so two tabs or a
     * finished AI run never silently overwrite each other's work. Confirmations stay as the server has
     * them: only {@see self::confirm()} writes them.
     *
     * @return array{revision: int, document: array, changed: bool}
     */
    public function saveDocument(Page $page, mixed $document, int $baseRevision): array
    {
        return DB::transaction(function () use ($page, $document, $baseRevision): array {
            $locked = Page::query()->lockForUpdate()->findOrFail($page->id);
            if ($locked->publish_state === 'publishing') {
                throw ValidationException::withMessages(['page' => 'Wait for publishing to finish before editing.']);
            }
            if ($locked->document_revision !== $baseRevision) {
                throw ValidationException::withMessages(['revision' => 'This page changed in another tab or by the AI. Reload to get the latest version.']);
            }
            if (is_array($document) && count(array_filter((array) ($document['sections'] ?? []), 'is_array')) > Document::MAX_SECTIONS) {
                throw ValidationException::withMessages(['page' => 'A page can have up to '.Document::MAX_SECTIONS.' sections. Remove one to save your changes.']);
            }
            $clean = Document::sanitize($document, $this->imageCheck($locked));
            $clean['confirmations'] = (array) ($locked->document['confirmations'] ?? []);
            if ($locked->hasDocument() && $this->same($clean, $locked->document)) {
                return ['revision' => $locked->document_revision, 'document' => $locked->document, 'changed' => false];
            }
            $this->startNextDraft($locked);
            $locked->document = $clean;
            $locked->document_revision++;
            $locked->save();

            return ['revision' => $locked->document_revision, 'document' => $clean, 'changed' => true];
        });
    }

    /** Page name and address. */
    public function saveDetails(Page $page, string $name, string $slug): void
    {
        $page->refresh();
        if ($page->name === $name && $page->slug === $slug) {
            return;
        }
        $this->edit($page, fn (Page $locked) => $locked->update(['name' => $name, 'slug' => $slug]));
    }

    /**
     * An owner vouches for one claim the sources do not back up. The confirmation records who and
     * when, travels with the published version, and lapses when the wording changes.
     */
    public function confirm(Page $page, string $key, User $actor): void
    {
        if (! Membership::of($actor, $page->workspace_id)?->canPublish()) {
            throw ValidationException::withMessages(['confirm' => 'Only workspace owners can confirm what a page claims.']);
        }
        $page->loadMissing('product');
        $brief = ProductBrief::for($page->product);
        $issue = $this->edit($page, function (Page $locked) use ($key, $actor, $brief): array {
            $issues = collect($this->claims->check((array) $locked->document, $brief));
            $issue = $issues->first(fn (array $issue) => $issue['key'] === $key && $issue['kind'] === 'confirm');
            if (! $issue) {
                throw ValidationException::withMessages(['confirm' => 'That text changed since it was checked. Look at it again.']);
            }
            $document = (array) $locked->document;
            $confirmations = (array) ($document['confirmations'] ?? []);
            if (count($confirmations) >= Document::MAX_CONFIRMATIONS) {
                // Old wording leaves confirmations behind. At the limit, keep only those for what the page says now.
                $confirmations = array_intersect_key($confirmations, $issues->pluck('key')->filter()->flip()->all());
            }
            unset($confirmations[$key]);
            $document['confirmations'] = $confirmations;
            $document['confirmations'][$key] = [
                'by' => $actor->id, 'name' => mb_substr((string) $actor->name, 0, 120), 'at' => now()->toIso8601String(),
                'text' => mb_substr($issue['where'].($issue['text'] !== '' ? ': '.$issue['text'] : ''), 0, 300),
            ];
            $locked->document = Document::sanitize($document, null, true);
            $locked->document_revision++;
            $locked->save();

            return $issue;
        });
        Workspace::find($page->workspace_id)?->auditEvents()->create([
            'actor_user_id' => $actor->id, 'event' => 'page_claim_confirmed', 'subject_type' => Page::class, 'subject_id' => $page->id,
            'metadata' => ['product_id' => $page->product_id, 'where' => $issue['where'], 'text' => $issue['text']],
        ]);
    }

    /**
     * What the page's checks found: blockers, and claims that need (or have) an owner's confirmation.
     *
     * @return list<array>
     */
    public function issues(Page $page, ?ProductBrief $brief = null): array
    {
        if (! $page->hasDocument()) {
            return array_map(fn (string $message) => ['key' => null, 'kind' => 'blocker', 'section' => null, 'path' => null, 'where' => 'Page', 'message' => $message, 'text' => '', 'confirmed' => null], $this->legacyBlockers($page));
        }
        $page->loadMissing('product');

        return $this->claims->check($page->document, $brief ?? ProductBrief::for($page->product));
    }

    /**
     * What stops this draft from being published.
     *
     * @return list<string>
     */
    public function blockers(Page $page): array
    {
        return $page->hasDocument() ? PageClaims::blocking($this->issues($page)) : $this->legacyBlockers($page);
    }

    /** The draft as it would be published. Older pages pin their content to exact approved versions. */
    public function snapshot(Page $page): array
    {
        $page->loadMissing('product.brand');
        $product = $page->product;
        if ($page->hasDocument()) {
            return [
                'format' => Document::FORMAT, 'page_id' => $page->id, 'name' => $page->name, 'slug' => $page->slug,
                'product' => ['id' => $product->id, 'name' => $product->name, 'brand' => $product->brand->name],
                'document' => $page->document,
            ];
        }

        return $this->legacySnapshot($page);
    }

    /**
     * Turns an older page's bound sections into a document, keeping what they showed. Their content
     * items stay in the library; the page no longer follows them.
     */
    public function convertLegacy(Page $page): Page
    {
        if ($page->hasDocument()) {
            return $page;
        }
        $page->loadMissing('product.brand');
        $brief = ProductBrief::for($page->product);
        $legacy = $this->legacySnapshot($page);
        $sections = [];
        $buyUrl = $brief->buyUrl;
        foreach ($legacy['sections'] as $entry) {
            $body = $entry['item']['body'] ?? [];
            $converted = match ($entry['kind']) {
                'hero' => [['product_hero', [
                    'images' => $entry['image'] ? [$entry['image']] : $brief->photos(),
                    'title' => (string) $page->headline, 'description' => mb_substr(trim((string) ($body['supporting_text'] ?? '')) ?: (string) ($entry['summary'] ?? ''), 0, 600),
                    'price' => (string) $brief->price, 'button_label' => 'Buy now',
                ]]],
                'message' => $body ? [['rich_text', $this->legacyMessage($body)]] : [],
                // Every offer the page showed, each as its own text section.
                'offer' => array_map(fn (array $offer) => ['rich_text', $this->legacyText((string) ($offer['wording'] ?? ''), [(string) ($offer['audience_or_situation'] ?? '')])],
                    array_values(array_filter((array) ($body['offers'] ?? []), 'is_array'))),
                'benefits' => $body ? [['benefits', ['heading' => 'Why you will love it', 'items' => array_map(fn ($angle) => ['title' => mb_substr((string) ($angle['title'] ?? ''), 0, 60), 'text' => mb_substr((string) ($angle['core_idea'] ?? ''), 0, 240)], array_slice((array) ($body['angles'] ?? []), 0, 8))]]] : [],
                'specs' => $entry['facts'] ? [['specs', ['rows' => array_map(fn ($fact) => ['label' => $fact['label'], 'value' => $fact['value']], $entry['facts'])]]] : [],
                'cta' => [['cta', ['heading' => mb_substr(trim((string) ($entry['settings']['text'] ?? '')) ?: 'Ready to buy?', 0, 120), 'button_label' => mb_substr((string) ($entry['settings']['label'] ?? 'Shop now'), 0, 40), 'button_url' => (string) ($entry['settings']['url'] ?? '')]]],
                default => [],
            };
            if ($entry['kind'] === 'cta' && $this->safeUrl($entry['settings']['url'] ?? null) && $buyUrl === '') {
                $buyUrl = (string) $entry['settings']['url'];
            }
            foreach ($converted as [$type, $settings]) {
                $sections[] = Document::section($type, $settings);
            }
        }
        $document = Document::sanitize(['theme' => Templates::theme('studio', $brief), 'buy_url' => $buyUrl, 'sections' => $sections]);

        $this->edit($page, function (Page $locked) use ($document): void {
            if ($locked->hasDocument()) {
                return;
            }
            $locked->update(['document' => $document, 'document_revision' => 1, 'template' => 'studio']);
            $locked->sections()->delete();
        });

        return $page->fresh();
    }

    /**
     * A positioning or messaging item as the older page showed it: the value proposition, then the
     * brand position, who it is made for and the proof points.
     */
    private function legacyMessage(array $body): array
    {
        $audiences = array_filter(array_map(fn ($audience) => is_array($audience)
            ? trim(($audience['name'] ?? '').(filled($audience['need'] ?? null) ? ': '.$audience['need'] : '')) : '', (array) ($body['audience_priorities'] ?? [])));
        $proof = array_filter(array_map(fn ($point) => is_string($point) ? trim($point) : '', (array) ($body['key_messaging']['proof_points'] ?? [])));

        return $this->legacyText((string) ($body['value_proposition'] ?? $body['key_messaging']['value_proposition'] ?? ''), [
            (string) ($body['brand_position'] ?? ''),
            $audiences ? "Made for:\n".implode("\n", $audiences) : '',
            implode("\n", $proof),
        ]);
    }

    /**
     * A heading and paragraphs for a text section. A heading too long for the field opens the text
     * instead, so nothing is cut.
     *
     * @param  list<string>  $paragraphs
     */
    private function legacyText(string $heading, array $paragraphs): array
    {
        $heading = trim($heading);
        if (mb_strlen($heading) > 140) {
            array_unshift($paragraphs, $heading);
            $heading = '';
        }

        return ['heading' => $heading, 'text' => implode("\n\n", array_filter(array_map('trim', $paragraphs), fn (string $text) => $text !== ''))];
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

    public function safeUrl(?string $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** A frozen (published) draft gets a new number before it changes. */
    private function startNextDraft(Page $locked): void
    {
        if (! $locked->draftIsFrozen()) {
            return;
        }
        $locked->draft_version++;
        if ($locked->publish_state === 'failed') {
            $locked->publish_state = $locked->live_version ? 'live' : 'idle';
            $locked->last_error = null;
        }
        $locked->save();
    }

    /**
     * Images a page may hold: its own product's. A banner that is not approved yet may stay, so the
     * publish checks can say why it cannot go out yet.
     */
    private function imageCheck(Page $page): callable
    {
        $known = [];

        return function (array $ref) use (&$known, $page): bool {
            return $known[$ref['key']] ??= $this->images->find($ref, $page->workspace_id, (int) $page->product_id) !== null;
        };
    }

    private function same(array $a, array $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    /** @return list<string> */
    private function legacyBlockers(Page $page): array
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

    /** An older page as it would be published: section content pinned to exact approved versions. */
    private function legacySnapshot(Page $page): array
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
                'facts' => [],
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
}
