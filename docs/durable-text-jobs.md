# Durable Text-Job Platform Decision

Decision date: 2026-07-19

Status: **Upstash QStash selected; not provisioned or enabled**

## Decision

Use Upstash QStash to deliver campaign-pack generation and section-regeneration jobs to a signed Laravel HTTP endpoint.

Keep the database `campaign_generation_jobs` record as the source of truth. QStash provides durable delivery; the existing atomic job claim, version creation, retry state, and credit refund logic continue to enforce application idempotency.

## Why QStash

- It delivers to an ordinary HTTPS endpoint, which fits the existing Laravel PHP function on Vercel without an always-on queue worker.
- Its free tier currently allows a 15-minute HTTP response, longer than Marketing Owl's 300-second Vercel function limit.
- It provides automatic retries, a dead-letter queue, flow control, and publish-time deduplication.
- Only the generation job ID needs to cross the queue boundary; workspace and product data remain in Supabase.

## Alternatives considered

### Vercel Queues

Vercel Queues is the preferred platform-native option for Node consumers, but the current app is a monolithic community PHP function. The documented push consumer uses a dedicated function and Node SDK callback handler. Adopting it now would require an additional runtime bridge or an unproven dedicated PHP trigger, increasing the launch path.

### Supabase database queue plus Vercel Cron

The database queue is already configured, but Vercel does not provide a continuously running Laravel queue worker. Cron polling would add latency and still bind processing to scheduled web requests.

### SQS plus an external worker

This is durable but adds an always-on worker platform, another deployment surface, and more credentials than the first paid-beta slice needs.

## Required security boundary

- Verify `Upstash-Signature` against the raw request body, destination URL, and both rotating signing keys before reading the payload.
- Reject missing or invalid signatures before database access.
- Accept only a numeric generation-job ID; fetch all other state from the database.
- Publish with a deduplication ID derived from the generation-job ID.
- Treat QStash as at-least-once delivery: completed, failed, or already-claimed jobs must acknowledge without creating another version or credit event.
- Return retryable 5xx responses only for transient failures. Terminal invalid messages must be acknowledged as non-retryable and recorded for support.
- Keep the consumer's flow-control parallelism low until production latency and provider limits are observed.

## Credential-free implementation sequence

1. Add a `qstash` processing mode behind `CampaignJobDispatcher`; keep the current mode as the default.
2. Add an HTTP publisher that sends only `generation_job_id`, a deduplication ID, and conservative retry/flow-control headers.
3. Add a signature-verifying consumer route that calls `CampaignJobRunner` after the existing atomic claim.
4. Add fixture-backed tests for publish success/failure, missing or invalid signature, duplicate delivery, transient retry, and terminal failure/refund.
5. Add QStash message/DLQ identifiers to support visibility without exposing credentials.
6. Complete preview QA by interrupting a generation request and redelivering the same message.

## Approval boundary

Do not create an Upstash account, provision QStash, add production environment variables, or change `CAMPAIGN_PROCESSING_MODE` without explicit owner approval.

Provisioning will require:

- `QSTASH_TOKEN`
- `QSTASH_CURRENT_SIGNING_KEY`
- `QSTASH_NEXT_SIGNING_KEY`

Production remains on its existing request-processing mode until preview interruption, retry, duplicate-delivery, and terminal-refund checks pass.
