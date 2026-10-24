<?php

return [
    // Seconds each store request may take. Publishing makes a few requests in one web request.
    'timeout_seconds' => (int) env('PAGES_STORE_TIMEOUT', 20),

    // How many times a publish is checked against the page address before it is reported as failed.
    'verify_checks' => (int) env('PAGES_VERIFY_CHECKS', 6),

    // How MarketingOwl names itself on WordPress's "approve this app" screen. The id must stay the same
    // so WordPress groups every MarketingOwl password under one app.
    'wordpress_app_name' => 'MarketingOwl',
    'wordpress_app_id' => '6f1c7a52-3b8e-4d0a-9c55-2e7d1b9f4a60',

    // Who writes page copy: "google" (Gemini on Vertex AI, like launch packs) or "mock" (from the
    // product brief, no AI). Follows the launch pack generator unless set on its own.
    'writer' => env('PAGE_WRITER', env('CAMPAIGN_GENERATOR', 'mock')),

    // AI runs (page writes and section rewrites) a workspace may start per hour.
    'ai_runs_per_hour' => (int) env('PAGE_AI_RUNS_PER_HOUR', 60),

    // Lets local development and tests publish to stores on private networks or odd ports.
    'allow_private_hosts' => (bool) env('PAGES_ALLOW_PRIVATE_HOSTS', in_array(env('APP_ENV'), ['local', 'testing'], true)),
];
