<?php

// Exercise the deployed entry point with environment values absent from PHP's arrays.
$storage = getenv('LARAVEL_STORAGE_PATH');
$getenvOnly = ! isset($_ENV['LARAVEL_STORAGE_PATH']) && ! isset($_SERVER['LARAVEL_STORAGE_PATH']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/login';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require __DIR__.'/../../api/index.php';
$body = ob_get_clean();

$result = [
    'getenv_only' => $getenvOnly,
    'storage' => $app->storagePath(),
    'binding' => $app->make('path.storage'),
    'cache' => config('cache.stores.file.path'),
    'sessions' => config('session.files'),
    'views' => config('view.compiled'),
    'log' => config('logging.channels.single.path'),
    'packages' => $app->getCachedPackagesPath(),
    'services' => $app->getCachedServicesPath(),
    'login_rendered' => str_contains($body, 'Sign in') && ! str_contains($body, 'Server Error'),
];

// Avoid writing outside disposable storage when reproducing a broken binding.
if ($result['storage'] === $storage) {
    cache()->store('file')->put('serverless-regression', 'persisted', 60);
    logger()->channel('single')->notice('Serverless storage regression');
    $result += [
        'cache_written' => cache()->store('file')->get('serverless-regression') === 'persisted',
        'session_written' => count(glob($storage.'/framework/sessions/*')) > 0,
        'view_written' => count(glob($storage.'/framework/views/*.php')) > 0,
        'log_written' => str_contains(file_get_contents($storage.'/logs/laravel.log'), 'Serverless storage regression'),
    ];
}

echo json_encode($result, JSON_THROW_ON_ERROR);
