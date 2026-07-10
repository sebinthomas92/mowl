# Marketing Owl SaaS

Agency-first campaign intelligence workspace. The first vertical slice persists:

`Workspace → Brand → Product → Source Snapshot → Campaign Pack → Version`

It supports account registration, private agency workspaces, tenant-scoped brand/product/pack libraries, creating a product-page source, generating a structured mock campaign pack, and copying individual sections or the whole approved pack.

## Current milestone

- Workspace-owner registration and session authentication
- Authenticated routes and logout
- Workspace membership with owner/member roles
- Tenant-scoped brand, product, source, and campaign-pack queries
- Brands, Products, and Campaign Packs libraries
- Existing-brand reuse in the pack builder
- Direct cross-workspace pack access blocked with a 404 response

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

## Processing boundary

`App\Services\MockCampaignPackGenerator` is the current generation adapter. Source snapshots, SHA-256 content hashes, versioned content, evidence references, compliance flags, and estimated costs are already stored separately.

The production processor can replace that adapter with a queued workflow that parses the product page, uses FFmpeg locally for media extraction, sends deduplicated frames and transcripts to the OpenAI Responses API, and writes the same version payload. Laravel's jobs table is included for queued processing. The next infrastructure slice is the text-only queued OpenAI pipeline, followed by S3-compatible media storage, provider usage records, and Stripe billing.

## Verification

```bash
php artisan test
npm run build
vendor/bin/pint --test
```
