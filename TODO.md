# Marketing Owl — Remaining Development

This is the execution backlog for the paid concierge beta. Work from the latest `main` branch in `sebinthomas92/mowl`.

## Current production baseline

- Live app: <https://app.marketingowl.ai>
- Stack: Laravel 13, Livewire 4, Supabase Postgres/Storage, Vercel PHP Functions
- Deployed vertical slice: workspace → brand → product → source snapshot → campaign pack → version
- Team operations: five-seat limit, secure invitations, owner controls
- Usage operations: credit ledger, provider cost ledger, COGS alerts
- Current verification baseline: 47 tests, 167 assertions
- Production generator: deterministic `mock`
- Production media uploads: disabled

## Execution rules

- [x] Start every milestone from the latest `origin/main` on a new `codex/*` branch.
- [ ] Preserve workspace isolation on every query and mutation.
- [ ] Store secrets only in Vercel/Supabase environment settings; never commit them.
- [x] Add tests before or with each behavioral change.
- [x] Run `php artisan test`, `vendor/bin/pint --test`, and `npm run build` before pushing.
- [x] Run desktop and mobile browser QA using `/Users/sebinthomas/.agent-tools/frontend-qa/AGENT_INSTRUCTIONS.md`.
- [x] Use a Vercel preview and Supabase-backed end-to-end test before merging.
- [x] Remove all QA users/workspaces from Supabase after hosted testing.
- [ ] Keep `CAMPAIGN_GENERATOR=mock` and media uploads disabled in production until their milestone acceptance checks pass.

---

## Milestone 4 — Real OpenAI generation (P0)

Dependency: approved production AI Gateway budget or `OPENAI_API_KEY`.

- [x] Review `OpenAIResponsesCampaignPackGenerator` against the current Responses API.
- [x] Confirm strict structured output covers every campaign-pack section.
- [x] Add retry/backoff for rate limits, timeouts, malformed structured output, and provider 5xx errors.
- [x] Preserve evidence references for every factual claim.
- [x] Flag unsupported claims instead of silently rewriting them as facts.
- [x] Record provider request ID, model, input/cached/output tokens, latency, and estimated cost.
- [x] Add provider/model fallbacks without bypassing the structured schema.
- [x] Add fixture-backed tests for success, refusal, invalid schema, timeout, and rate limit responses.
- [x] Add an owner-only setting or environment guard for switching `mock` → `openai`.
- [x] Run real standard and premium packs against representative ecommerce product pages.
- [x] Verify standard pack COGS is normally ≤ $0.25 and alerting begins at $0.50.
- [x] Enable `CAMPAIGN_GENERATOR=openai` in preview only.
- [x] Complete hosted pack generation, regeneration, evidence, compliance, and cost-ledger QA.
- [ ] Enable OpenAI in production only after the preview checks pass.

Acceptance criteria:

- A real product URL produces a complete, copy-ready pack without mock content.
- All factual claims are source-linked or visibly flagged as unsupported.
- Provider usage and cost appear correctly on the Usage & Cost screen.
- Failures are recoverable and do not consume credits permanently.

> Milestone 4 implementation status (2026-07-13): Supabase schema migration for `provider_latency_ms` is applied and verified. A protected Vercel AI Gateway preview passes real standard ($0.004149) and premium ($0.006498) pack generation, evidence, cost ledger, and section-regeneration QA using `openai/gpt-5.4-mini`; all hosted QA data was deleted. Production remains deterministic mock mode pending an approved AI budget or production API key.

---

## Milestone 5 — Stripe paid beta (P0)

Dependencies: Stripe account, product/price IDs, webhook secret.

- [ ] Install the official Stripe PHP SDK or Laravel Cashier after checking current compatibility.
- [ ] Add billing/subscription fields without storing sensitive payment data.
- [ ] Create the $129/month concierge beta price.
- [ ] Build owner-only checkout and billing-management flows.
- [ ] Implement signed Stripe webhooks with idempotency.
- [ ] Handle checkout completion, renewal, cancellation, failed payment, and refund events.
- [ ] Gate paid features using server-side subscription entitlement checks.
- [ ] Keep existing workspaces accessible in a safe read-only state after payment failure/cancellation.
- [ ] Add monthly allocation of 50 pack credits exactly once per paid billing cycle.
- [ ] Add $2 per-credit overage metering and reconciliation.
- [ ] Ensure premium deep analysis consumes three credits.
- [ ] Ensure one pack credit includes the initial generation plus three section regenerations within 24 hours.
- [ ] Prevent unlimited or negative-credit usage.
- [ ] Add Billing UI showing plan, seats, brands, credits, overages, next renewal, and payment status.
- [ ] Test webhook replays, out-of-order events, duplicate events, and failed renewals.
- [ ] Run Stripe test-mode checkout and webhook QA on Vercel preview.

Acceptance criteria:

- An owner can subscribe, manage billing, and see the correct entitlement state.
- Each paid cycle grants exactly 50 credits once.
- Overage is billed at $2 per credit and is auditable.
- Duplicate webhooks cannot duplicate credits or charges.

---

## Milestone 6 — Durable media and FFmpeg worker (P0)

Dependencies: S3-compatible credentials and selected worker platform.

- [ ] Configure the existing private `marketing-owl-media` bucket through S3-compatible credentials.
- [ ] Store originals and derivatives under workspace/product-scoped paths.
- [ ] Add signed upload/download URLs and enforce workspace authorization.
- [ ] Choose durable worker compute for FFmpeg; do not run long video processing in the PHP web request.
- [ ] Connect web app → durable queue → media worker → database status updates.
- [ ] Validate duration, MIME type, file size, extension, and content hash server-side.
- [ ] Extract audio and scene candidates locally in the worker.
- [ ] Produce only 8–16 deduplicated 512px frames.
- [ ] Transcribe extracted audio through the configured provider.
- [ ] Cache originals, transcripts, frames, and analysis by content hash.
- [ ] Add retry, timeout, stale-job recovery, and dead-letter handling.
- [ ] Add retention/deletion behavior for originals and derivatives.
- [ ] Add media-processing cost and duration telemetry.
- [ ] Test malicious files, oversized files, duplicate uploads, failed FFmpeg, and partial retries.
- [ ] Enable media uploads in preview and complete image/video pack QA.
- [ ] Enable `CAMPAIGN_MEDIA_UPLOADS_ENABLED=true` in production only after worker QA passes.

Acceptance criteria:

- A short video is uploaded privately, processed asynchronously, and contributes transcript/frame evidence to a pack.
- Web requests do not wait for FFmpeg.
- Duplicate media reuses cached derivatives.
- Cross-workspace media access is impossible.

---

## Milestone 7 — Durable campaign job infrastructure (P1)

> Platform decision (2026-07-19): use Upstash QStash for durable text-generation delivery. It fits the existing PHP-on-Vercel request boundary without an always-on worker and provides signed HTTP delivery, retries, deduplication, flow control, and a DLQ. The service is selected but not provisioned; production flags stay unchanged until owner approval, credentials, and hosted interruption/redelivery QA. See `docs/durable-text-jobs.md`.

- [ ] Move long OpenAI/media work from request-bound execution to a durable queue.
- [ ] Define idempotency keys for every job and section regeneration.
- [ ] Add exponential retry with terminal failure states.
- [ ] Add stale-job recovery scheduling.
- [ ] Add dead-job visibility to the Usage/Admin surfaces.
- [ ] Preserve atomic credit charge/refund behavior across retries.
- [ ] Add concurrency protection for duplicate user clicks and webhook/job redelivery.
- [ ] Add structured logs and alerts for elevated failure rate, latency, and cost.

Acceptance criteria:

- Jobs survive function restarts and provider timeouts.
- Duplicate delivery cannot create duplicate versions or credit events.
- Owners can see and safely retry terminal failures.

---

## Milestone 8 — Transactional email and account recovery (P1)

Dependency: transactional email provider credentials and verified sending domain.

- [ ] Configure production mail provider and `FROM` identity.
- [ ] Send workspace invitation emails using the existing secure invite tokens.
- [x] Add password-reset request and reset flows.
- [ ] Add email verification for new accounts.
- [ ] Add resend verification/invitation actions with throttling.
- [ ] Add subscription, payment-failure, credit-low, job-failed, and cost-alert emails.
- [ ] Ensure email links use `https://app.marketingowl.ai`.
- [ ] Add notification preference storage for non-critical messages.
- [x] Test expired, reused, tampered, and wrong-email links.

Acceptance criteria:

- Users can recover accounts without administrator intervention.
- Invitation and verification links are single-purpose, expiring, and safely throttled.

---

## Milestone 9 — Workspace and concierge administration (P1)

- [x] Make the sidebar workspace selector interactive for multi-workspace users.
- [x] Add workspace settings: name, owner, members, limits, billing summary.
- [x] Build a protected internal concierge admin area.
- [x] Add account lookup, job inspection, credit adjustments, and customer notes.
- [x] Require an adjustment reason and write an immutable audit event.
- [x] Add safe job retry/cancel controls.
- [x] Add cost-alert and failed-job queues for support.
- [x] Add beta onboarding state and concierge checklist.
- [x] Protect admin access with explicit allow-list/role checks and audit logging.

Acceptance criteria:

- Support can diagnose a customer issue without direct database access.
- Every admin mutation is attributable and auditable.

---

## Milestone 10 — Human approval and collaboration (P2)

- [x] Replace automatic approval with draft → review → approved/rejected states.
- [x] Add section-level comments and review notes.
- [x] Record approver identity and timestamp.
- [x] Lock approved versions; changes create a new version.
- [x] Add source-truth approval and refresh history.
- [x] Add client-ready share links with revocation and expiry.
- [x] Add downloadable PDF/CSV/text campaign-pack exports.
- [x] Add an audit trail for approvals, exports, regeneration, and sharing.

Acceptance criteria:

- Media buyers can distinguish draft copy from explicitly approved copy.
- Approved versions are immutable and traceable to their evidence snapshot.

---

## Milestone 11 — Security and production hardening (P2)

- [x] Add authentication throttling to login/register/reset actions.
- [ ] Add bot protection to login/register/reset routes.
- [x] Add security headers and a reviewed Content Security Policy.
- [ ] Verify SSRF protection for redirects, IPv4/IPv6, DNS rebinding, and non-standard ports.
- [ ] Add file malware/content validation before media processing.
- [ ] Add workspace audit logs and data-retention controls.
- [ ] Add account/workspace deletion with safe delayed purge.
- [ ] Verify Supabase backup and restore procedures.
- [ ] Add production error, latency, queue-depth, and COGS alerts.
- [ ] Add `/security.txt` and operational incident contacts.
- [ ] Run dependency, authorization, tenant-isolation, and secrets scans before paid launch.

Acceptance criteria:

- No known high-severity dependency or tenant-isolation findings remain.
- Backup restore and customer-data deletion are tested and documented.

---

## Launch gate

Do not open paid self-service signup until all boxes below are complete:

- [ ] Real OpenAI generation is enabled and cost-verified.
- [ ] Stripe subscription and webhook flows pass test mode.
- [ ] Monthly credits and overage billing are idempotent.
- [ ] Password reset and verified transactional email work.
- [ ] Durable queue/recovery exists for production jobs.
- [ ] Media uploads are either fully production-ready or clearly unavailable in the paid offer.
- [ ] Concierge admin/support tools are usable.
- [ ] Production monitoring, backups, and security checks pass.
- [ ] Desktop/mobile paid-user journey passes on `https://app.marketingowl.ai`.

## Credential checklist for the owner

- [ ] OpenAI production API key and approved spend limit.
- [ ] Stripe test/live keys, price ID, and webhook secret.
- [ ] S3-compatible access key, secret, endpoint, region, and bucket confirmation.
- [ ] Worker/queue platform credentials.
- [ ] Transactional email API key and verified sending domain.
- [ ] Monitoring/alert destination for production incidents.
