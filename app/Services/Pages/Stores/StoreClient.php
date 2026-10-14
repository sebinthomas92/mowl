<?php

namespace App\Services\Pages\Stores;

use App\Models\Page;
use App\Models\StoreConnection;

interface StoreClient
{
    /**
     * Confirms the store can be reached and the credentials may publish pages.
     *
     * @return array{api_base: ?string, has_woocommerce: bool}
     */
    public function check(StoreConnection $store): array;

    /** @return array{id: string, url: string} */
    public function uploadImage(StoreConnection $store, array $ref, int $workspaceId): array;

    /**
     * Creates the page, or updates it when $remoteId still exists in the store.
     *
     * @return array{id: string, url: string}
     */
    public function putPage(StoreConnection $store, Page $page, ?string $remoteId, string $title, string $html): array;

    /** The public page as a visitor would get it. */
    public function fetchPublic(StoreConnection $store, Page $page, string $url): string;
}
