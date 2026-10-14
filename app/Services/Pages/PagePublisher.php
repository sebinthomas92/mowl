<?php

namespace App\Services\Pages;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PublishAttempt;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PageActivity;
use App\Services\Pages\Stores\StoreClients;
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
        $store = $page->storeConnection;
        if (! $attempt || ! $store) {
            return $attempt;
        }
        $version = $attempt->pageVersion->version;

        try {
            $served = $this->renderer->serves($this->clients->for($store)->fetchPublic($store, $page, $attempt->remote_url), $page->id, $version);
            $problem = null;
        } catch (StoreException $exception) {
            [$served, $problem] = [false, $exception->getMessage()];
        }

        if ($served) {
            DB::transaction(function () use ($page, $attempt, $version): void {
                $attempt->update(['status' => 'live', 'finished_at' => now(), 'error' => null]);
                $page->update(['publish_state' => 'live', 'live_version' => $version, 'verified_at' => now(), 'last_error' => null]);
            });
            $this->notify($page, $attempt->action === 'rollback' ? 'rolled_back' : 'published', $version, $attempt->user);

            return $attempt->fresh();
        }

        $attempt->increment('verify_checks');
        if ($attempt->verify_checks >= config('pages.verify_checks')) {
            $error = "The store accepted v{$version}, but {$attempt->remote_url} still does not serve it after {$attempt->verify_checks} checks"
                .($problem ? " ({$problem})" : '').'. A cache may be holding the old page. Check the store, then publish again.';
            DB::transaction(function () use ($page, $attempt, $error): void {
                $attempt->update(['status' => 'unverified', 'error' => mb_substr($error, 0, 500), 'finished_at' => now()]);
                $page->update(['publish_state' => 'failed', 'last_error' => mb_substr($error, 0, 500)]);
            });
            $this->notify($page, 'publish_failed', $version, $attempt->user);
        }

        return $attempt->fresh();
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
                $version = $page->versions()->where('version', $page->draft_version)->first()
                    ?? PageVersion::create([
                        'page_id' => $page->id, 'version' => $page->draft_version,
                        'snapshot' => $this->builder->snapshot($page), 'created_by_user_id' => $actor->id,
                    ]);
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
        try {
            $client = $this->clients->for($store);
            $html = $this->renderer->page($version->snapshot, $version->version, fn (array $ref) => $this->storeImage($store, $client, $ref, $page->workspace_id));
            $attempt->update(['html' => $html]);
            $remote = $client->putPage($store, $page, $page->remote_id, $html);
        } catch (StoreException $exception) {
            $this->fail($page, $attempt, $store, $exception);

            return $attempt->fresh();
        } catch (Throwable $exception) {
            $this->fail($page, $attempt, $store, new StoreException('Publishing stopped unexpectedly. Nothing on the live page changed.'));
            report($exception);

            return $attempt->fresh();
        }

        DB::transaction(function () use ($page, $attempt, $remote): void {
            $attempt->update(['status' => 'verifying', 'remote_url' => $remote['url']]);
            $page->update(['publish_state' => 'verifying', 'remote_id' => $remote['id'], 'remote_url' => $remote['url']]);
        });
        $this->audit($page, $actor, $attempt->action === 'rollback' ? 'page_rolled_back' : 'page_published', ['version' => $version->version, 'url' => $remote['url']]);

        return $this->verify($page->fresh()) ?? $attempt->fresh();
    }

    /** Uploads an image once per store and reuses it on later publishes. */
    private function storeImage(StoreConnection $store, $client, array $ref, int $workspaceId): string
    {
        $media = $store->media ?? [];
        if (! isset($media[$ref['key']]['url'])) {
            $media[$ref['key']] = $client->uploadImage($store, $ref, $workspaceId);
            $store->update(['media' => $media]);
        }

        return $media[$ref['key']]['url'];
    }

    private function fail(Page $page, PublishAttempt $attempt, StoreConnection $store, StoreException $exception): void
    {
        $live = $page->live_version ? " The live page is unchanged (v{$page->live_version})." : ' Nothing was published.';
        $error = mb_substr($exception->getMessage().$live, 0, 500);
        DB::transaction(function () use ($page, $attempt, $store, $exception, $error): void {
            $attempt->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            $page->update(['publish_state' => 'failed', 'last_error' => $error]);
            if ($exception->auth) {
                $store->update(['status' => 'needs_attention', 'last_error' => mb_substr($exception->getMessage(), 0, 500)]);
            }
        });
        $this->notify($page, 'publish_failed', $attempt->pageVersion->version, $attempt->user);
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
        $recipients = $workspace?->users()->when($actor, fn ($query) => $query->whereKeyNot($actor->id))->get() ?? collect();
        Notification::send($recipients, new PageActivity($event, $page, $version));
    }
}
