<?php

namespace App\Services\Pages;

use App\Models\StoreConnection;
use App\Models\User;
use App\Services\Pages\Stores\StoreClients;
use App\Services\Pages\Stores\WooCommerceStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Checks and saves store connections, from the Settings form or from WordPress's own "approve this
 * app" screen, which hands back an application password so the owner never copies one.
 */
class StoreConnector
{
    /** How long an owner has to approve MarketingOwl in WordPress. */
    private const APPROVAL_MINUTES = 30;

    public function __construct(private StoreClients $clients, private WooCommerceStore $wordpress) {}

    /** Checks the store with its credentials, then saves it. */
    public function connect(StoreConnection $store, User $actor): StoreConnection
    {
        $result = $this->clients->for($store)->check($store);
        $isNew = ! $store->exists;
        $moved = ! $isNew && $store->isDirty('site_url');
        if ($moved && $store->pages()->whereIn('publish_state', ['publishing', 'verifying'])->exists()) {
            throw new StoreException('A page is publishing to this store. Change the address once it finishes.');
        }
        DB::transaction(function () use ($store, $result, $moved): void {
            if ($moved) {
                // A new address is a different site: its pages and images have to be created there again.
                $store->media = null;
                $store->pages()->update([
                    'remote_id' => null, 'remote_url' => null, 'remote_store' => null, 'live_version' => null,
                    'publish_state' => 'idle', 'verified_at' => null, 'last_error' => null,
                ]);
            }
            $store->fill($result + ['status' => 'connected', 'last_error' => null, 'checked_at' => now()])->save();
        });
        $store->workspace->auditEvents()->create([
            'actor_user_id' => $actor->id, 'event' => $isNew ? 'store_connection_added' : 'store_connection_updated',
            'subject_type' => StoreConnection::class, 'subject_id' => $store->id,
            'metadata' => ['name' => $store->name, 'driver' => $store->driver, 'site_url' => $store->site_url],
        ]);

        return $store;
    }

    /**
     * The WordPress approval address for this store. What the owner typed travels with it, encrypted,
     * and comes back on the return trip; WordPress adds the username and a new application password.
     */
    public function approvalUrl(StoreConnection $store, User $actor, string $returnUrl): string
    {
        $endpoint = $this->wordpress->authorization($store);
        $state = Crypt::encrypt([
            'user' => $actor->id, 'workspace' => $store->workspace_id, 'store' => $store->id, 'name' => $store->name,
            'brand' => $store->brand_id, 'site' => $store->site_url, 'api' => $endpoint['api_base'],
            'expires' => now()->addMinutes(self::APPROVAL_MINUTES)->timestamp,
        ]);
        $back = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').http_build_query(['state' => $state]);

        return $endpoint['url'].(str_contains($endpoint['url'], '?') ? '&' : '?').http_build_query([
            'app_name' => config('pages.wordpress_app_name'),
            'app_id' => config('pages.wordpress_app_id'),
            'success_url' => $back,
            // WordPress returns to reject_url exactly as given, so it carries the "declined" flag itself.
            'reject_url' => $back.'&success=false',
        ]);
    }

    /**
     * What the owner asked for before leaving for WordPress, if the state is genuine, theirs and fresh.
     *
     * @return array<string, mixed>|null
     */
    public function pendingApproval(string $state, User $actor): ?array
    {
        try {
            $pending = Crypt::decrypt($state);
        } catch (\Throwable) {
            return null;
        }

        return is_array($pending) && ($pending['user'] ?? null) === $actor->id && ($pending['expires'] ?? 0) >= now()->timestamp ? $pending : null;
    }
}
