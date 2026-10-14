<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

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

if (file_exists($maintenance = $storagePath.'/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
// getenv() alone does not populate the arrays Laravel consults for storage_path().
$app->useStoragePath($storagePath);
$app->handleRequest(Request::capture());
