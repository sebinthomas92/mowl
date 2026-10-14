<?php

namespace App\Services;

use App\Models\CampaignPack;
use App\Models\CampaignPackVersion;
use App\Models\ContentComment;
use App\Models\ContentItem;
use App\Models\ContentVersion;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ContentActivity;
use App\Support\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Launch pack outputs as separate content items, each with its own versions and approval.
 *
 * A pack version is split into one item per section (positioning, ranked angles, each video route,
 * offers, channel copy) plus one item per finished banner. When every section item of a pack is
 * approved, the approved bodies are assembled back into an approved pack version, so exports,
 * banners and the client hub keep reading pack versions.
 */
class ContentLibrary
{
    /** Sections a single generation job can regenerate, keyed by item section prefix. */
    public const REGENERABLE = ['positioning' => 'positioning', 'ranked_angles' => 'ranked_angles', 'creative_routes' => 'creative_routes', 'offers' => 'offers'];

    /** Production runs migrations as a separate step; callers fall back to whole-pack review until the tables exist. */
    public static function available(): bool
    {
        // Cached on the container, so a request or test sees tables that a migration adds later.
        $container = app();
        if ($container->bound('content-library.available')) {
            return $container->make('content-library.available');
        }
        $available = Schema::hasTable('content_items') && Schema::hasTable('content_client_links') && Schema::hasColumn('campaign_packs', 'brief');
        if ($available && DB::getDriverName() === 'pgsql') {
            try {
                $available = (bool) DB::scalar("select has_table_privilege(current_user, 'content_items', 'SELECT') and has_table_privilege(current_user, 'content_client_links', 'SELECT')");
            } catch (QueryException) {
                $available = false;
            }
        }
        $container->instance('content-library.available', $available);

        return $available;
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> section => [type, title, body] */
    public function sections(array $content): array
    {
        $sections = [];
        if (isset($content['positioning']) && is_array($content['positioning'])) {
            $sections['positioning'] = ['copy', 'Positioning', $content['positioning']];
        }
        if (isset($content['ranked_angles']) && is_array($content['ranked_angles'])) {
            $sections['ranked_angles'] = ['copy', 'Ranked angles', ['angles' => array_values($content['ranked_angles'])]];
        }
        foreach (array_values($content['creative_routes'] ?? []) as $index => $route) {
            $name = trim((string) ($route['name'] ?? ''));
            $sections["creative_routes.{$index}"] = ['video', mb_substr('Video route '.($index + 1).($name !== '' ? ' · '.$name : ''), 0, 160), $route];
        }
        if (isset($content['offers']) && is_array($content['offers'])) {
            $sections['offers'] = ['copy', 'Offer framing', ['offers' => array_values($content['offers'])]];
        }
        if (! empty($content['marketing_hub']) && is_array($content['marketing_hub'])) {
            $sections['marketing_hub'] = ['copy', 'Channel copy', $content['marketing_hub']];
        }

        return $sections;
    }

    /** Writes an item body back into pack content. */
    public function apply(array $content, string $section, array $body): array
    {
        if ($section === 'ranked_angles') {
            $content['ranked_angles'] = array_values($body['angles'] ?? []);
        } elseif ($section === 'offers') {
            $content['offers'] = array_values($body['offers'] ?? []);
        } elseif (str_starts_with($section, 'creative_routes.')) {
            $content['creative_routes'][(int) substr($section, strlen('creative_routes.'))] = $body;
        } else {
            $content[$section] = $body;
        }

        return $content;
    }

    /** Creates or updates items for every pack version and finished banner not yet seen. Safe to call repeatedly. */
    public function syncPack(CampaignPack $pack): void
    {
        if (! self::available()) {
            return;
        }
        $pack->loadMissing(['product.brand', 'sourceSnapshot']);
        $versions = $pack->versions()->orderBy('version')->get();
        $normalizer = app(CampaignPackContentNormalizer::class);
        $items = $pack->contentItems()->whereNotNull('section')->get()->keyBy('section');
        $previous = [];

        foreach ($versions as $packVersion) {
            $sections = $this->sections($normalizer->normalize($packVersion->content ?? [], $pack->product, $pack->sourceSnapshot));
            foreach ($sections as $section => [$type, $title, $body]) {
                $item = $items[$section] ?? null;
                if ($item && $item->synced_pack_version >= $packVersion->version) {
                    continue;
                }
                $changed = ! array_key_exists($section, $previous) || $previous[$section] != $body;
                $items[$section] = DB::transaction(fn () => $this->syncSection($pack, $packVersion, $item, $section, $type, $title, $body, $changed));
            }
            $previous = array_map(fn (array $section) => $section[2], $sections);
        }

        if (Schema::hasTable('banner_creatives')) {
            foreach ($pack->bannerCreatives()->where('status', 'completed')->get() as $creative) {
                if ($items->has("banner.{$creative->id}")) {
                    continue;
                }
                DB::transaction(function () use ($pack, $creative): void {
                    $item = ContentItem::firstOrCreate(
                        ['campaign_pack_id' => $pack->id, 'section' => "banner.{$creative->id}"],
                        [
                            'workspace_id' => $pack->product->brand->workspace_id, 'product_id' => $pack->product_id,
                            'banner_creative_id' => $creative->id, 'type' => 'image', 'status' => 'draft', 'current_version' => 1,
                            'title' => mb_substr('Banner · '.($creative->direction ?: 'Creative '.$creative->sequence), 0, 160),
                        ],
                    );
                    if ($item->wasRecentlyCreated) {
                        $item->versions()->create(['version' => 1, 'status' => 'draft', 'note' => 'Generated in Banner Studio', 'body' => [
                            'headline' => (string) $creative->headline, 'supporting_text' => (string) $creative->supporting_text,
                            'cta' => (string) $creative->cta, 'direction' => (string) $creative->direction,
                            'size' => $creative->width && $creative->height ? "{$creative->width}×{$creative->height}" : '1080×1350',
                        ]]);
                    }
                });
            }
        }

        $this->refreshPackStatus($pack);
    }

    private function syncSection(CampaignPack $pack, CampaignPackVersion $packVersion, ?ContentItem $item, string $section, string $type, string $title, array $body, bool $changed): ContentItem
    {
        $status = ['approved' => 'approved', 'review' => 'review', 'rejected' => 'changes'][$packVersion->review_status ?? 'draft'] ?? 'draft';
        $item ??= ContentItem::create([
            'workspace_id' => $pack->product->brand->workspace_id, 'product_id' => $pack->product_id, 'campaign_pack_id' => $pack->id,
            'section' => $section, 'type' => $type, 'title' => $title, 'status' => $status, 'current_version' => 0,
        ]);
        $latest = $item->versions()->orderByDesc('version')->first();

        if (! $latest || ($changed && $latest->body != $body)) {
            $next = ($latest?->version ?? 0) + 1;
            $version = $item->versions()->create([
                'version' => $next, 'body' => $body, 'status' => $status, 'campaign_pack_version_id' => $packVersion->id,
                'note' => $latest ? "Regenerated in launch pack v{$packVersion->version}" : "Generated in launch pack v{$packVersion->version}",
                'reviewed_by_user_id' => in_array($status, ['approved', 'changes'], true) ? $packVersion->reviewed_by_user_id : null,
                'reviewed_at' => in_array($status, ['approved', 'changes'], true) ? $packVersion->reviewed_at : null,
            ]);
            if ($status === 'changes' && filled($packVersion->review_note)) {
                $this->addComment($version, $packVersion->review_note, $packVersion->reviewed_by_user_id ? User::find($packVersion->reviewed_by_user_id) : null);
            }
            $item->fill(['title' => $title, 'type' => $type, 'status' => $status, 'current_version' => $next]);
        } elseif ($packVersion->review_status === 'approved' && $latest->body == $body && $latest->status !== 'approved') {
            // The pack was approved as a whole before items existed; carry the approval over.
            $latest->update(['status' => 'approved', 'reviewed_by_user_id' => $packVersion->reviewed_by_user_id, 'reviewed_at' => $packVersion->reviewed_at ?? now()]);
            $item->status = 'approved';
        }

        $item->synced_pack_version = $packVersion->version;
        $item->save();

        return $item;
    }

    public function submit(ContentVersion $version, User $actor): void
    {
        $this->assertCurrent($version, ['draft']);
        DB::transaction(function () use ($version): void {
            $version->update(['status' => 'review', 'submitted_at' => now()]);
            $version->item->update(['status' => 'review']);
        });
        $this->refreshPackStatus($version->item->campaignPack);
        $this->notify('review_requested', $version, $actor);
    }

    /** Owners approve in the app; a client approves through their review link with $actor null. */
    public function approve(ContentVersion $version, ?User $actor, ?string $clientName = null, ?string $note = null): void
    {
        $this->assertCurrent($version, ['draft', 'review']);
        $blocking = collect($this->checks($version))->where('blocking', true)->where('ok', false)->first();
        if ($blocking) {
            throw ValidationException::withMessages(['decision' => 'Approval blocked: '.$blocking['label']]);
        }

        DB::transaction(function () use ($version, $actor, $clientName, $note): void {
            $version->update([
                'status' => 'approved', 'reviewed_by_user_id' => $actor?->id, 'reviewed_by_name' => $clientName ?? $actor?->name, 'reviewed_at' => now(),
            ]);
            $version->item->update(['status' => 'approved']);
            if (filled($note)) {
                $this->addComment($version, $note, $actor, $clientName);
            }
        });
        $this->approvePackIfComplete($version->item->campaignPack, $actor);
        $this->notify('approved', $version, $actor, $clientName);
    }

    public function requestChanges(ContentVersion $version, ?User $actor, string $comment, ?string $clientName = null): void
    {
        $this->assertCurrent($version, ['draft', 'review']);
        if (trim($comment) === '') {
            throw ValidationException::withMessages(['decision' => 'Say what should change so the author can act on it.']);
        }
        DB::transaction(function () use ($version, $actor, $comment, $clientName): void {
            $version->update(['status' => 'changes', 'reviewed_by_user_id' => $actor?->id, 'reviewed_by_name' => $clientName ?? $actor?->name, 'reviewed_at' => now()]);
            $version->item->update(['status' => 'changes']);
            $this->addComment($version, $comment, $actor, $clientName);
        });
        $this->refreshPackStatus($version->item->campaignPack);
        $this->notify('changes_requested', $version, $actor, $clientName);
    }

    /** Starts the next version as an editable draft, copied from the latest one. */
    public function revise(ContentItem $item, User $actor): ContentVersion
    {
        $latest = $item->versions()->orderByDesc('version')->firstOrFail();
        abort_unless(in_array($latest->status, ['changes', 'approved'], true), 422);

        $version = DB::transaction(function () use ($item, $latest, $actor): ContentVersion {
            $version = $item->versions()->create([
                'version' => $latest->version + 1, 'body' => $latest->body, 'status' => 'draft',
                'note' => $latest->status === 'changes' ? 'Revised after feedback' : "Revision of approved v{$latest->version}",
                'created_by_user_id' => $actor->id,
            ]);
            $item->update(['status' => 'draft', 'current_version' => $version->version]);

            return $version;
        });
        $this->refreshPackStatus($item->campaignPack);

        return $version;
    }

    /** A new, separate draft item. The original stays as it is and keeps feeding its launch pack. */
    public function variation(ContentItem $item, User $actor): ContentItem
    {
        $latest = $item->versions()->orderByDesc('version')->firstOrFail();

        return DB::transaction(function () use ($item, $latest, $actor): ContentItem {
            $copy = ContentItem::create([
                'workspace_id' => $item->workspace_id, 'product_id' => $item->product_id, 'campaign_pack_id' => $item->campaign_pack_id,
                'banner_creative_id' => $item->banner_creative_id, 'type' => $item->type, 'status' => 'draft', 'current_version' => 1,
                'title' => mb_substr($item->title.' · variation', 0, 160), 'variation_of_id' => $item->id, 'created_by_user_id' => $actor->id,
            ]);
            $copy->versions()->create([
                'version' => 1, 'body' => $latest->body, 'status' => 'draft', 'created_by_user_id' => $actor->id,
                'note' => "Variation of {$item->title} v{$latest->version}",
            ]);

            return $copy;
        });
    }

    /** Saves edited text fields on a draft. Keys are dot paths into the body. */
    public function saveDraft(ContentVersion $version, array $fields): void
    {
        $this->assertCurrent($version, ['draft']);
        $body = $version->body;
        foreach ($fields as $path => $value) {
            $current = data_get($body, $path);
            if (is_array($current) && array_is_list($current) && collect($current)->every(fn ($entry) => is_string($entry))) {
                data_set($body, $path, array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value)), fn ($line) => $line !== '')));
            } elseif (is_string($current) || $current === null) {
                data_set($body, $path, mb_substr(trim((string) $value), 0, 4000));
            }
        }
        $version->update(['body' => $body]);
    }

    public function addComment(ContentVersion $version, string $body, ?User $actor, ?string $clientName = null): ContentComment
    {
        $role = $clientName ? 'client' : (Membership::of($actor, $version->item->workspace_id)?->role ?? 'member');

        return $version->comments()->create([
            'workspace_id' => $version->item->workspace_id, 'user_id' => $actor?->id,
            'author_name' => mb_substr($clientName ?? $actor?->name ?? 'Someone', 0, 120), 'author_role' => $role,
            'body' => mb_substr(trim($body), 0, 2000),
        ]);
    }

    public function comment(ContentVersion $version, User $actor, string $body): void
    {
        $this->addComment($version, $body, $actor);
        $this->notify('commented', $version, $actor);
    }

    /**
     * Automatic checks shown beside a decision. Blocking ones stop approval.
     *
     * @return list<array{ok: bool, label: string, blocking: bool}>
     */
    public function checks(ContentVersion $version): array
    {
        $item = $version->item;
        $product = $item->product()->with('brand')->first();
        $text = $this->flatten($version->body);
        $checks = [];

        $price = ProductKnowledge::available() ? $product->facts()->where('key', 'price')->where('status', 'ok')->value('confirmed_value') : $product->price;
        $mentioned = $this->prices($text);
        if ($price && $mentioned) {
            $confirmed = $this->prices($price) ?: [$this->number($price)];
            $wrong = collect($mentioned)->reject(fn ($amount) => in_array($amount, $confirmed, true))->first();
            $checks[] = ['ok' => $wrong === null, 'blocking' => true, 'label' => $wrong === null
                ? "Price matches confirmed Knowledge ({$price})"
                : "Price {$wrong} differs from confirmed Knowledge ({$price})"];
        } elseif ($mentioned && ! $price) {
            $checks[] = ['ok' => false, 'blocking' => false, 'label' => 'Mentions a price, but Knowledge has no confirmed price'];
        }

        if (ProductKnowledge::available()) {
            $knowledge = app(ProductKnowledge::class);
            $source = $item->campaignPack?->sourceSnapshot;
            $excluded = collect($knowledge->excludedValues($product, $source?->extracted_truth ?? []))->filter(fn ($value) => mb_strlen((string) $value) >= 4);
            $used = $excluded->first(fn ($value) => str_contains(mb_strtolower($text), mb_strtolower((string) $value)));
            $checks[] = ['ok' => $used === null, 'blocking' => true, 'label' => $used === null ? 'No excluded facts used' : 'Uses an excluded fact: '.mb_strimwidth((string) $used, 0, 60, '…')];
        }

        if ($item->isPackSection()) {
            $pack = $item->campaignPack;
            $latest = $pack->versions()->orderByDesc('version')->first();
            $content = $this->apply($this->assemble($pack), $item->section, $version->body);
            $issues = $latest ? app(CampaignPackSafetyValidator::class)->issues($content, $latest->evidence ?? [], $latest->compliance_flags ?? [], $pack->sourceSnapshot) : [];
            $checks[] = ['ok' => $issues === [], 'blocking' => true, 'label' => $issues === [] ? 'Claims, timing and source checks pass' : $issues[0]['message']];
        }

        $voice = app(ProductKnowledge::class)->brandProfile($product)['voice'] ?? null;
        $checks[] = ['ok' => (bool) $voice, 'blocking' => false, 'label' => $voice ? "Brand voice: {$product->brand->name}" : "{$product->brand->name} has no brand voice yet"];

        return $checks;
    }

    /** Pack content with every section replaced by its latest approved item version. */
    public function assemble(CampaignPack $pack): array
    {
        $latest = $pack->versions()->orderByDesc('version')->first();
        $content = $latest ? app(CampaignPackContentNormalizer::class)->normalize($latest->content ?? [], $pack->product, $pack->sourceSnapshot) : [];
        foreach ($pack->contentItems()->get()->filter->isPackSection() as $item) {
            $approved = $item->versions()->where('status', 'approved')->orderByDesc('version')->first();
            if ($approved) {
                $content = $this->apply($content, $item->section, $approved->body);
            }
        }

        return $content;
    }

    /** Once every section is approved, the pack gets an approved version that matches them exactly. */
    public function approvePackIfComplete(?CampaignPack $pack, ?User $actor): void
    {
        if (! $pack) {
            return;
        }
        $items = $pack->contentItems()->get()->filter->isPackSection();
        $latest = $pack->versions()->orderByDesc('version')->first();
        $expected = $latest ? count($this->sections(app(CampaignPackContentNormalizer::class)->normalize($latest->content ?? [], $pack->product, $pack->sourceSnapshot))) : 0;

        if ($latest && $items->count() >= $expected && $items->every(fn (ContentItem $item) => $item->status === 'approved')) {
            DB::transaction(function () use ($pack, $latest, $actor): void {
                $content = $this->assemble($pack);
                $current = app(CampaignPackContentNormalizer::class)->normalize($latest->content ?? [], $pack->product, $pack->sourceSnapshot);
                if ($current == $content) {
                    if ($latest->review_status !== 'approved') {
                        $latest->update(['review_status' => 'approved', 'reviewed_by_user_id' => $actor?->id, 'reviewed_at' => now()]);
                    }
                    $pack->update(['status' => 'approved', 'current_version' => $latest->version]);

                    return;
                }
                $next = $latest->version + 1;
                $pack->versions()->create([
                    'version' => $next, 'content' => $content, 'evidence' => $latest->evidence, 'compliance_flags' => $latest->compliance_flags,
                    'generator' => 'assembled', 'review_status' => 'approved', 'reviewed_by_user_id' => $actor?->id, 'reviewed_at' => now(),
                    'review_note' => 'Assembled from approved content items.',
                ]);
                $pack->contentItems()->whereNotNull('section')->update(['synced_pack_version' => $next]);
                $pack->update(['status' => 'approved', 'current_version' => $next]);
            });

            return;
        }

        $this->refreshPackStatus($pack);
    }

    /** The pack's status follows its items while its current version is not yet approved. */
    public function refreshPackStatus(?CampaignPack $pack): void
    {
        if (! $pack || ! in_array($pack->status, ['draft', 'review', 'rejected', 'approved'], true)) {
            return;
        }
        $current = $pack->versions()->where('version', $pack->current_version)->value('review_status');
        if ($current === 'approved') {
            $pack->status === 'approved' || $pack->update(['status' => 'approved']);

            return;
        }
        $statuses = $pack->contentItems()->get()->filter->isPackSection()->pluck('status');
        $status = $statuses->contains('review') ? 'review' : ($statuses->contains('changes') ? 'rejected' : 'draft');
        $pack->status === $status || $pack->update(['status' => $status]);
    }

    /** Items the workspace can see, with their latest version, newest first. */
    public function items(Workspace $workspace, ?Product $product = null, ?Membership $membership = null): Collection
    {
        $query = ContentItem::query()->where('workspace_id', $workspace->id);
        $membership?->scopeThroughProduct($query);

        return $query
            ->when($product, fn ($query) => $query->where('product_id', $product->id))
            ->with(['latestVersion', 'product.brand', 'campaignPack', 'bannerCreative'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get();
    }

    /** Backfills items for packs that predate content items. */
    public function syncWorkspace(Workspace $workspace, ?Product $product = null): void
    {
        if (! self::available()) {
            return;
        }
        CampaignPack::query()
            ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($product, fn ($query) => $query->where('product_id', $product->id))
            ->where('current_version', '>', 0)
            ->get()
            ->each(fn (CampaignPack $pack) => $this->syncPack($pack));
    }

    private function assertCurrent(ContentVersion $version, array $statuses): void
    {
        $item = $version->item;
        if ($version->version !== $item->current_version || ! in_array($version->status, $statuses, true)) {
            throw ValidationException::withMessages(['decision' => "Version {$version->version} can no longer be changed. Reload to see the latest version."]);
        }
    }

    private function notify(string $event, ContentVersion $version, ?User $actor, ?string $clientName = null): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        $workspace = Workspace::find($version->item->workspace_id);
        $recipients = Membership::audience($workspace, $version->item->product?->brand_id)->when($actor, fn ($query) => $query->whereKeyNot($actor->id));
        if ($event === 'review_requested') {
            $recipients->wherePivotIn('role', ['owner', 'reviewer']);
        }
        Notification::send($recipients->get(), new ContentActivity($event, $version, $actor?->name ?? $clientName ?? 'A client'));
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(fn ($entry) => $this->flatten($entry), $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** Currency amounts in a text, as plain numbers ("$1,499.00" becomes "1499"). */
    private function prices(string $text): array
    {
        preg_match_all('/(?:[$€£₹]|\b(?:USD|EUR|GBP|INR|Rs\.?))\s?(\d[\d,]*(?:\.\d{1,2})?)|(\d[\d,]*(?:\.\d{1,2})?)\s?(?:USD|EUR|GBP|INR)\b/u', $text, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(fn (array $match) => $this->number($match[1] !== '' ? $match[1] : ($match[2] ?? '')), $matches)));
    }

    private function number(string $value): string
    {
        $number = preg_replace('/[^\d.]/', '', str_replace(',', '', $value));

        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }
}
