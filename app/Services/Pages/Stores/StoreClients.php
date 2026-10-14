<?php

namespace App\Services\Pages\Stores;

use App\Models\StoreConnection;

class StoreClients
{
    public function for(StoreConnection $store): StoreClient
    {
        return match ($store->driver) {
            'sandbox' => app(SandboxStore::class),
            default => app(WooCommerceStore::class),
        };
    }
}
