<?php

use Illuminate\Http\Request;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(__DIR__.'/../../public');
$asset = realpath($public.$path);
if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset) && pathinfo($asset, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
if (getenv('APP_ENV') !== 'e2e') {
    throw new RuntimeException('The test router is only available in the E2E environment.');
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath(getenv('E2E_STORAGE'));
$app->loadEnvironmentFrom('e2e-unused.env');
$app->handleRequest(Request::capture());
