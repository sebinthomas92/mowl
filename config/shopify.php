<?php

return [
    'client_id' => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    'api_version' => '2026-10',
    'scopes' => ['write_online_store_pages', 'write_files'],
];
