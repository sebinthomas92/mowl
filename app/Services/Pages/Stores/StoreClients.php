<?php

namespace App\Services\Pages\Stores;

use App\Models\StoreConnection;

class StoreClients
{
    public function for(StoreConnection $store): StoreClient
    {
        return match ($store->driver) {
            'sandbox' => app(SandboxStore::class),
            'shopify' => app(ShopifyStore::class),
            default => app(WooCommerceStore::class),
        };
    }
}
