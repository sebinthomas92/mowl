<?php

return [
    'generator' => env('CAMPAIGN_GENERATOR', 'mock'),
    'queue' => env('CAMPAIGN_QUEUE', 'campaigns'),
    'processing_mode' => env('CAMPAIGN_PROCESSING_MODE', 'queue'),
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
];
