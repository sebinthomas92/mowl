# Marketing Owl SaaS

Agency-first campaign intelligence workspace. The first vertical slice persists:

`Workspace → Brand → Product → Source Snapshot → Campaign Pack → Version`

It supports account registration, private agency workspaces, tenant-scoped brand/product/pack libraries, queued product-page extraction, structured campaign-pack generation, local media preparation, section regeneration, and copying individual sections or the whole approved pack.

## Current milestone

- Workspace-owner registration and session authentication
- Authenticated routes and logout
- Workspace membership with owner/member roles
- Five-seat beta enforcement with hashed, seven-day invite links, owner controls, and invited-user onboarding
- Tenant-scoped brand, product, source, and campaign-pack queries
- Brands, Products, and Campaign Packs libraries
- Existing-brand reuse in the pack builder
- Direct cross-workspace pack access blocked with a 404 response
- Safe product-page fetching with private-network blocking, redirect checks, normalized extraction, and content-hash caching
- Queued generation with progress, retries, automatic credit refunds, version history, and three included section regenerations within 24 hours
- Standard (1 credit) and premium deep analysis (3 credits), with a 50-credit beta allocation
- Provider usage, request IDs, estimated COGS, cache hits, and the $0.50 cost alert stored per job
- Workspace usage dashboard for credit balance, provider COGS, alerts, and recent generation history
- Optional image/video uploads with FFmpeg audio extraction and 8–16 deduplicated 512px frame candidates
- Mock and OpenAI Responses API generators behind the same structured result contract

## Run locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
composer run dev
```

`composer run dev` starts the web app, frontend watcher, and default queue worker. To run the campaign worker separately:

```bash
php artisan queue:work --queue=campaigns --tries=3 --timeout=180
```

## Processing boundary

`App\Contracts\CampaignPackGenerator` is the boundary between processing and generation. The default `mock` driver makes local development deterministic. Set these values to use the real text-and-image pipeline:

```dotenv
CAMPAIGN_GENERATOR=openai
OPENAI_API_KEY=
OPENAI_CAMPAIGN_MODEL=gpt-5.4-mini
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
```

The OpenAI adapter uses strict structured output and writes the same version payload as the mock driver. Without an API key, page extraction and FFmpeg processing still complete locally and audio transcription remains `pending_credentials`.

Media uses Laravel's configured filesystem disk. `CAMPAIGN_MEDIA_DISK=local` is the local default; an S3-compatible disk can be selected after its endpoint and credentials are configured. FFmpeg and FFprobe default to Homebrew paths and can be overridden with `FFMPEG_PATH` and `FFPROBE_PATH`.

## Vercel + Supabase deployment

The beta web app runs on Vercel's PHP 8.4 function runtime, close to Supabase in Mumbai. FFmpeg remains packaged in `Dockerfile.vercel` for a future on-demand media worker; production media uploads stay disabled until that durable worker and S3 credentials are configured. Production state stays in Supabase Postgres and S3-compatible Storage. Configure Vercel with:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=https://app.marketingowl.ai
LOG_CHANNEL=stderr

DB_CONNECTION=pgsql
DB_URL=
DB_SSLMODE=require
DB_EMULATE_PREPARES=true

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
CAMPAIGN_PROCESSING_MODE=request
CRON_SECRET=

FILESYSTEM_DISK=s3
CAMPAIGN_MEDIA_DISK=s3
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Use the Supabase transaction-pooler connection string for `DB_URL`. Run migrations once against the production database:

```bash
php artisan migrate --force
```

In request-processing mode, the pack page starts a signed generation request and safely resumes stale jobs when reopened. The authenticated `/internal/campaign-jobs/recover` endpoint is also ready for a Vercel Cron once the project moves to Pro; Vercel supplies `CRON_SECRET` as its bearer token.

## Verification

```bash
php artisan test
npm run build
vendor/bin/pint --test
```
