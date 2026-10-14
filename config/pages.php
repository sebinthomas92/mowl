<?php

return [
    // Seconds each store request may take. Publishing makes a few requests in one web request.
    'timeout_seconds' => (int) env('PAGES_STORE_TIMEOUT', 20),

    // How many times a publish is checked against the page address before it is reported as failed.
    'verify_checks' => (int) env('PAGES_VERIFY_CHECKS', 6),

    // Lets local development and tests publish to stores on private networks or odd ports.
    'allow_private_hosts' => (bool) env('PAGES_ALLOW_PRIVATE_HOSTS', in_array(env('APP_ENV'), ['local', 'testing'], true)),
];
