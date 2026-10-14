<?php

namespace App\Services\Pages\Stores;

use App\Models\Page;
use App\Models\PublishAttempt;
use App\Models\StoreConnection;
use App\Services\Pages\StoreException;

/**
 * A test store hosted by the app. Publishing to it runs the same publish, verify and rollback steps
 * as a real store, and the page is served at a private address so people can open what went live.
 */
class SandboxStore implements StoreClient
{
    /** Attempt states whose HTML reached the store. */
    public const SERVED = ['verifying', 'live', 'unverified'];

    public function check(StoreConnection $store): array
    {
        return ['api_base' => $store->api_base, 'has_woocommerce' => false];
    }

    public function uploadImage(StoreConnection $store, array $ref, int $workspaceId): array
    {
        return ['id' => $ref['key'], 'url' => route('sandbox-store.media', [$store->api_base, $ref['key']])];
    }

    public function putPage(StoreConnection $store, Page $page, ?string $remoteId, string $title, string $html): array
    {
        return ['id' => (string) $page->id, 'url' => route('sandbox-store.page', [$store->api_base, $page->id, $page->slug])];
    }

    public function fetchPublic(StoreConnection $store, Page $page, string $url): string
    {
        return self::served($store, $page)?->html ?? throw new StoreException('The sandbox store has no page at this address.');
    }

    /** What the sandbox currently serves for a page: the latest attempt that reached it. */
    public static function served(StoreConnection $store, Page $page): ?PublishAttempt
    {
        return $page->attempts()->where('store_connection_id', $store->id)->whereIn('status', self::SERVED)->latest('id')->first();
    }
}
