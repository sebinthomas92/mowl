<?php

namespace App\Services\Pages;

use App\Models\ContentVersion;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PublishAttempt;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PageActivity;
use App\Services\Pages\Stores\StoreClients;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Publish, verify, live. A publish freezes the draft into a page version, sends it to the store and
 * then fetches the page address until it serves that version. Until then the page is not called live,
 * and a failure leaves whatever the store served before untouched.
 */
class PagePublisher
{
    public function __construct(
        private PageBuilder $builder,
        private PageRenderer $renderer,
        private PageImages $images,
        private StoreClients $clients,
    ) {}

    public function publish(Page $page, User $actor): PublishAttempt
    {
        return $this->send($page, $actor, null);
    }

    /** Re-publishes an earlier version that was live before. The draft is kept as it is. */
    public function rollback(Page $page, User $actor, int $version): PublishAttempt
    {
        return $this->send($page, $actor, $version);
    }

    /** Checks the page address for the version being published. Safe to call repeatedly. */
    public function verify(Page $page): ?PublishAttempt
    {
        $attempt = $page->attempts()->where('status', 'verifying')->latest('id')->with('pageVersion')->first();
        if (! $attempt) {
            return null;
        }
        $version = $attempt->pageVersion->version;
        $store = $attempt->store_connection_id ? StoreConnection::find($attempt->store_connection_id) : null;
        if (! $store || $store->id !== $page->storeConnection?->id) {
            // The store was removed or swapped while this publish waited; nothing more can be checked.
            $this->finish($page, $attempt, 'failed', 'The store this publish went to is no longer connected to the page. Choose a store, then publish again.');
            $this->notify($page, 'publish_failed', $version, $attempt->user);

            return $attempt->fresh();
        }

        try {
            $served = $this->renderer->serves($this->clients->for($store)->fetchPublic($store, $page, $attempt->remote_url), $page->id, $version);
            $problem = null;
        } catch (StoreException $exception) {
            [$served, $problem] = [false, $exception->getMessage()];
        }

        if ($served) {
            if ($this->finish($page, $attempt, 'live', null, ['live_version' => $version, 'verified_at' => now()])) {
                $this->notify($page, $attempt->action === 'rollback' ? 'rolled_back' : 'published', $version, $attempt->user);
            }

            return $attempt->fresh();
        }

        $checks = DB::transaction(function () use ($attempt): ?int {
            $locked = PublishAttempt::query()->lockForUpdate()->find($attempt->id);
            if ($locked?->status !== 'verifying') {
                return null;
            }
            $locked->increment('verify_checks');

            return $locked->verify_checks;
        });
        if ($checks !== null && $checks >= config('pages.verify_checks')) {
            $error = "The store accepted v{$version}, but {$attempt->remote_url} still does not serve it after {$checks} checks"
                .($problem ? " ({$problem})" : '').'. A cache may be holding the old page. Check the store, then publish again.';
            if ($this->finish($page, $attempt, 'unverified', $error)) {
                $this->notify($page, 'publish_failed', $version, $attempt->user);
            }
        }

        return $attempt->fresh();
    }

    /**
     * Ends a verifying attempt once. Overlapping checks (two tabs polling) cannot both finish it, and a
     * check that started before a newer publish cannot overwrite the page's state.
     */
    private function finish(Page $page, PublishAttempt $attempt, string $status, ?string $error, array $pageFields = []): bool
    {
        return DB::transaction(function () use ($page, $attempt, $status, $error, $pageFields): bool {
            $locked = PublishAttempt::query()->lockForUpdate()->find($attempt->id);
            if ($locked?->status !== 'verifying') {
                return false;
            }
            $error = $error === null ? null : mb_substr($error, 0, 500);
            $locked->update(['status' => $status, 'error' => $error, 'finished_at' => now()]);
            $latest = (int) $page->attempts()->max('id') === $attempt->id;
            if ($latest) {
                $page->update(['publish_state' => $status === 'live' ? 'live' : 'failed', 'last_error' => $error] + $pageFields);
            }

            return true;
        });
    }

    private function send(Page $page, User $actor, ?int $rollbackTo): PublishAttempt
    {
        $this->authorize($page, $actor);
        [$page, $attempt] = DB::transaction(function () use ($page, $actor, $rollbackTo): array {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->isBusy()) {
                throw ValidationException::withMessages(['publish' => 'A publish is already running for this page.']);
            }
            $store = $page->storeConnection;
            if (! $store) {
                throw ValidationException::withMessages(['publish' => 'Choose a store to publish to.']);
            }
            if ($store->workspace_id !== $page->workspace_id || ($store->brand_id && $store->brand_id !== $page->product?->brand_id)) {
                throw ValidationException::withMessages(['publish' => "{$store->name} belongs to another brand. Choose this product's store, then publish again."]);
            }
            if (! $store->isConnected()) {
                throw ValidationException::withMessages(['publish' => "{$store->name} needs attention. Reconnect it in Settings, then publish again."]);
            }

            if ($rollbackTo !== null) {
                $version = $page->versions()->where('version', $rollbackTo)->first();
                $wasLive = $version && $page->attempts()->where('page_version_id', $version->id)->where('status', 'live')->exists();
                if (! $wasLive || $rollbackTo === $page->live_version) {
                    throw ValidationException::withMessages(['publish' => "Version {$rollbackTo} cannot be rolled back to."]);
                }
            } else {
                if ($blockers = $this->builder->blockers($page)) {
                    throw ValidationException::withMessages(['publish' => $blockers[0]]);
                }
                if ($page->live_version === $page->draft_version && $page->publish_state === 'live') {
                    throw ValidationException::withMessages(['publish' => "v{$page->draft_version} is already live."]);
                }
                $version = $page->versions()->where('version', $page->draft_version)->first();
                if (! $version) {
                    $snapshot = $this->builder->snapshot($page);
                    // Content can change between the blocker check and the snapshot; only approved versions go out.
                    $pinned = collect($snapshot['sections'])->pluck('item.content_version_id')->filter();
                    if ($pinned->isNotEmpty() && ContentVersion::query()->whereIn('id', $pinned)->where('status', '!=', 'approved')->exists()) {
                        throw ValidationException::withMessages(['publish' => 'Some content on this page changed while publishing. Check the sections, then publish again.']);
                    }
                    $version = PageVersion::create([
                        'page_id' => $page->id, 'version' => $page->draft_version, 'snapshot' => $snapshot, 'created_by_user_id' => $actor->id,
                    ]);
                }
            }

            $page->update(['publish_state' => 'publishing', 'last_error' => null]);
            $attempt = $page->attempts()->create([
                'page_version_id' => $version->id, 'store_connection_id' => $store->id, 'user_id' => $actor->id,
                'action' => $rollbackTo !== null ? 'rollback' : 'publish', 'status' => 'publishing',
            ]);

            return [$page, $attempt->setRelation('pageVersion', $version)];
        });

        $store = $page->storeConnection;
        $version = $attempt->pageVersion;
        $writing = false;
        try {
            $client = $this->clients->for($store);
            $html = $this->renderer->page($version->snapshot, $version->version, fn (array $ref) => $this->storeImage($store, $client, $ref, $page->workspace_id));
            $attempt->update(['html' => $html]);
            // A page id only means something on the store (and address) it was created on.
            $remoteId = $page->remote_store === $store->remoteKey() ? $page->remote_id : null;
            $writing = true;
            $remote = $client->putPage($store, $page, $remoteId, (string) ($version->snapshot['name'] ?? $page->name), $html);
            $current = DB::transaction(function () use ($page, $attempt, $store, $remote): bool {
                // The attempt may have been ended meanwhile (its store removed); a late answer must not revive it.
                $locked = PublishAttempt::query()->lockForUpdate()->find($attempt->id);
                if ($locked?->status !== 'publishing') {
                    return false;
                }
                $locked->update(['status' => 'verifying', 'remote_url' => $remote['url']]);
                if ((int) $page->attempts()->max('id') === $attempt->id) {
                    $page->update(['publish_state' => 'verifying', 'remote_id' => $remote['id'], 'remote_url' => $remote['url'], 'remote_store' => $store->remoteKey()]);
                }

                return true;
            });
            if (! $current) {
                return $attempt->fresh();
            }
        } catch (StoreException $exception) {
            $this->fail($page, $attempt, $store, $exception, $writing && $exception->unsure);

            return $attempt->fresh();
        } catch (Throwable $exception) {
            $this->fail($page, $attempt, $store, new StoreException('Publishing stopped unexpectedly.'), $writing);
            report($exception);

            return $attempt->fresh();
        }

        $this->audit($page, $actor, $attempt->action === 'rollback' ? 'page_rolled_back' : 'page_published', ['version' => $version->version, 'url' => $remote['url']]);

        return $this->verify($page->fresh()) ?? $attempt->fresh();
    }

    /** Uploads an image once per store and reuses it on later publishes. */
    private function storeImage(StoreConnection $store, $client, array $ref, int $workspaceId): string
    {
        $cached = ($store->media ?? [])[$ref['key']]['url'] ?? null;
        if ($cached) {
            return $cached;
        }
        $uploaded = $client->uploadImage($store, $ref, $workspaceId);
        // Other pages may be publishing to this store too; merge into the latest map instead of replacing it.
        DB::transaction(function () use ($store, $ref, $uploaded): void {
            $locked = StoreConnection::query()->lockForUpdate()->findOrFail($store->id);
            $locked->update(['media' => [$ref['key'] => $uploaded] + ($locked->media ?? [])]);
            $store->setRawAttributes($locked->getAttributes(), true);
        });

        return $uploaded['url'];
    }

    /** @param  bool  $maybeWritten  the page was being sent when the store stopped answering */
    private function fail(Page $page, PublishAttempt $attempt, StoreConnection $store, StoreException $exception, bool $maybeWritten = false): void
    {
        $outcome = match (true) {
            $maybeWritten => ' The store may have saved the page anyway. Check it in the store, then publish again.',
            (bool) $page->live_version => " The live page is unchanged (v{$page->live_version}).",
            default => ' Nothing was published.',
        };
        $error = mb_substr($exception->getMessage().$outcome, 0, 500);
        $failed = DB::transaction(function () use ($page, $attempt, $store, $exception, $error): bool {
            $locked = PublishAttempt::query()->lockForUpdate()->find($attempt->id);
            if ($locked?->status !== 'publishing') {
                return false;
            }
            $locked->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            if ((int) $page->attempts()->max('id') === $attempt->id) {
                $page->update(['publish_state' => 'failed', 'last_error' => $error]);
            }
            if ($exception->auth) {
                $store->update(['status' => 'needs_attention', 'last_error' => mb_substr($exception->getMessage(), 0, 500)]);
            }

            return true;
        });
        if ($failed) {
            $this->notify($page, 'publish_failed', $attempt->pageVersion->version, $attempt->user);
        }
    }

    private function authorize(Page $page, User $actor): void
    {
        $owner = Workspace::find($page->workspace_id)?->users()->whereKey($actor->id)->wherePivot('role', 'owner')->exists();
        if (! $owner) {
            throw ValidationException::withMessages(['publish' => 'Only workspace owners can publish pages.']);
        }
    }

    private function audit(Page $page, User $actor, string $event, array $metadata): void
    {
        Workspace::find($page->workspace_id)?->auditEvents()->create([
            'actor_user_id' => $actor->id, 'event' => $event, 'subject_type' => Page::class, 'subject_id' => $page->id,
            'metadata' => ['product_id' => $page->product_id] + $metadata,
        ]);
    }

    private function notify(Page $page, string $event, int $version, ?User $actor): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        $workspace = Workspace::find($page->workspace_id);
        $recipients = $workspace
            ? Membership::audience($workspace, $page->product?->brand_id)->when($actor, fn ($query) => $query->whereKeyNot($actor->id))->get()
            : collect();
        Notification::send($recipients, new PageActivity($event, $page, $version));
    }
}
