<?php

return [
    'generator' => env('CAMPAIGN_GENERATOR', 'mock'),
    'queue' => env('CAMPAIGN_QUEUE', 'campaigns'),
    'processing_mode' => env('CAMPAIGN_PROCESSING_MODE', 'queue'),
    // Request mode runs inside one Vercel function (maxDuration 300s in vercel.json); provider calls stop here.
    'request_budget_seconds' => (int) env('CAMPAIGN_REQUEST_BUDGET_SECONDS', 240),
    // A request-mode job still "processing" after this long was killed and is retried.
    'stale_after_minutes' => (int) env('CAMPAIGN_STALE_AFTER_MINUTES', 6),
    'cron_secret' => env('CRON_SECRET'),
    'monthly_credits' => (int) env('CAMPAIGN_MONTHLY_CREDITS', 50),
    'brand_limit' => (int) env('CAMPAIGN_BRAND_LIMIT', 10),
    'seat_limit' => (int) env('CAMPAIGN_SEAT_LIMIT', 5),
    'cogs_target' => (float) env('CAMPAIGN_COGS_TARGET', 0.25),
    'cogs_alert' => (float) env('CAMPAIGN_COGS_ALERT', 0.50),
    'concierge_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CAMPAIGN_CONCIERGE_EMAILS', '')),
    ))),
    'source' => [
        'timeout_seconds' => 20,
        'max_redirects' => 3,
        'max_bytes' => 2_000_000,
        'max_image_bytes' => 15_000_000,
        'max_image_candidates' => 12,
        'max_extracted_characters' => 60_000,
    ],
    'media' => [
        'uploads_enabled' => env('CAMPAIGN_MEDIA_UPLOADS_ENABLED', true),
        'disk' => env('CAMPAIGN_MEDIA_DISK', 'local'),
        'ffmpeg' => env('FFMPEG_PATH', '/opt/homebrew/bin/ffmpeg'),
        'ffprobe' => env('FFPROBE_PATH', '/opt/homebrew/bin/ffprobe'),
        'max_video_seconds' => 90,
        'min_frames' => 8,
        'max_frames' => 16,
        'scene_threshold' => 0.18,
        'dedupe_hamming_distance' => 8,
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
    ],
    'banners' => [
        'enabled' => env('BANNER_STUDIO_ENABLED', true),
        'generator' => env('BANNER_GENERATOR', 'mock'),
        'model' => env('GOOGLE_BANNER_MODEL', 'gemini-3.1-flash-image'),
        'included_count' => 3,
        'additional_credit_cost' => 1,
        'aspect_ratio' => '4:5',
        'image_size' => '1K',
        'width' => 1080,
        'height' => 1350,
        'retry_attempts' => 3,
        'max_input_images' => 1,
        // Each background is checked for readable third-party text (book covers, magazine names,
        // logos) and regenerated up to this many times per banner attempt.
        'inspect_images' => env('BANNER_IMAGE_INSPECTION', true),
        'inspection_attempts' => (int) env('BANNER_INSPECTION_ATTEMPTS', 2),
        // An inspection normally answers in seconds; a stalled one must not eat the attempt's budget.
        'inspection_timeout_seconds' => 30,
        // Image checks ask for little reasoning (Gemini 3 thinkingLevel); empty leaves the model's default.
        'inspection_thinking_level' => env('BANNER_INSPECTION_THINKING', 'LOW'),
        // The product cutout is compared with the store's product photo once, before it is kept (and,
        // with compositing off, every drawn background is, and redrawn if it shows a different object).
        // It runs only while inspect_images is on, which switches every image check off together.
        'check_product' => env('BANNER_PRODUCT_CHECK', true),
        // One banner attempt (generations plus inspections) stays under the 180s queue worker timeout;
        // a new round starts only with enough time left for a generation and an inspection.
        'time_budget_seconds' => 160,
        'min_round_seconds' => 70,
        // Waits before retrying a call Vertex rejected with 429 (per-minute quota), inside the time budget.
        'rate_limit_backoff_seconds' => [10, 25, 45],
        // Pause before a failed banner is re-run from the pack page (inside Vercel's 300s with the 240s budget).
        'retry_pause_seconds' => 30,
        'reference_timeout_seconds' => 60,
        // The image model draws only the scene; the product is cut out of its store photo once (checked
        // against it) and composited on top, so it cannot be redrawn as something else. Pack 20's stand
        // came back as a paper book on wood through eight rounds of redraw-and-check.
        'composite_product' => env('BANNER_COMPOSITE_PRODUCT', true),
        // A studio cutout's outline comes from a key-colour matte the image model draws over the photo
        // (its pixels stay the photo's own); colour alone left the backdrop seen between the stand's pages.
        'matte_studio_cutouts' => env('BANNER_STUDIO_MATTE', true),
        'disk' => env('BANNER_STORAGE_DISK', env('CAMPAIGN_MEDIA_DISK', 'local')),
        'prices_per_million' => [
            'input' => 0.50,
            'output_text' => 3.00,
            'output_image' => 60.00,
        ],
    ],
    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_CAMPAIGN_MODEL', 'gpt-5.4-mini'),
        'fallback_models' => array_values(array_filter(array_map('trim', explode(',', env('OPENAI_CAMPAIGN_FALLBACK_MODELS', ''))))),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 120),
        'retry_attempts' => (int) env('OPENAI_RETRY_ATTEMPTS', 3),
        'retry_backoff_ms' => [250, 1000],
        'prices_per_million' => [
            'gpt-5.4-mini' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 4.50],
            'openai/gpt-5.4-mini' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 4.50],
        ],
    ],
    'google' => [
        'project' => env('GOOGLE_CLOUD_PROJECT'),
        'project_number' => env('GOOGLE_CLOUD_PROJECT_NUMBER'),
        'location' => env('GOOGLE_CLOUD_LOCATION', 'global'),
        'model' => env('GOOGLE_GEMINI_MODEL', 'gemini-3.5-flash'),
        'service_account_email' => env('GOOGLE_SERVICE_ACCOUNT_EMAIL'),
        'vercel_audience' => env('GCP_AUDIENCE'),
        'vercel_token_exchange_url' => env('VERCEL_OIDC_TOKEN_EXCHANGE_URL', 'https://oidc.vercel.com/~token'),
        'base_url' => env('GOOGLE_VERTEX_BASE_URL', 'https://aiplatform.googleapis.com/v1'),
        'sts_audience' => env('GOOGLE_STS_AUDIENCE'),
        'sts_token_url' => env('GOOGLE_STS_TOKEN_URL', 'https://sts.googleapis.com/v1/token'),
        'service_account_impersonation_url' => env('GOOGLE_SERVICE_ACCOUNT_IMPERSONATION_URL'),
        'timeout_seconds' => (int) env('GOOGLE_VERTEX_TIMEOUT_SECONDS', 120),
        'retry_attempts' => (int) env('GOOGLE_VERTEX_RETRY_ATTEMPTS', 3),
        'retry_backoff_ms' => [250, 1000],
        'prices_per_million' => [
            'gemini-3.5-flash' => ['input' => 2.70, 'cached_input' => 0.27, 'output' => 16.20],
        ],
    ],
];
