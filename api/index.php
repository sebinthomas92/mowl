<?php

$storagePath = getenv('LARAVEL_STORAGE_PATH') ?: '/tmp/marketing-owl-storage';

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
