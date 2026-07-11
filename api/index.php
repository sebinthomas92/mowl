<?php

$storagePath = getenv('LARAVEL_STORAGE_PATH') ?: '/tmp/marketing-owl-storage';
$bootstrapCachePath = $storagePath.'/framework/cache';

foreach ([
    'APP_CONFIG_CACHE' => $bootstrapCachePath.'/config.php',
    'APP_EVENTS_CACHE' => $bootstrapCachePath.'/events.php',
    'APP_PACKAGES_CACHE' => $bootstrapCachePath.'/packages.php',
    'APP_ROUTES_CACHE' => $bootstrapCachePath.'/routes.php',
    'APP_SERVICES_CACHE' => $bootstrapCachePath.'/services.php',
] as $key => $value) {
    putenv($key.'='.$value);
}

foreach ([
    'app/private',
    'framework/cache/data',
    'framework/sessions',
    'framework/views',
    'logs',
] as $directory) {
    if (! is_dir($path = $storagePath.'/'.$directory)) {
        mkdir($path, 0775, true);
    }
}

require __DIR__.'/../public/index.php';
