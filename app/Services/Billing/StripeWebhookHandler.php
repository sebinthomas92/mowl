<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Models\WorkspaceSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies Stripe events to workspaces. Every effect is idempotent: credit grants are keyed on the
 * Stripe invoice or payment, and subscription state is re-read from Stripe rather than taken from
 * the event, so duplicate and out-of-order deliveries settle on the same result.
 */
class StripeWebhookHandler
{
    public function __construct(private readonly StripeClient $stripe) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $object = (array) Arr::get($event, 'data.object', []);

        match ($event['type'] ?? null) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->checkoutCompleted($object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
            'customer.subscription.paused', 'customer.subscription.resumed' => $this->refreshSubscription((string) ($object['id'] ?? '')),
            'invoice.paid' => $this->invoicePaid($object),
            'invoice.payment_failed' => $this->refreshSubscription((string) $this->invoiceSubscriptionId($object)),
            'charge.refunded' => $this->chargeRefunded($object),
            default => null,
        };
    }

    /** @param array<string, mixed> $session */
    public function checkoutCompleted(array $session): void
    {
        $workspace = $this->workspaceFor((string) ($session['customer'] ?? ''), $session['metadata']['workspace_id'] ?? null);
        if (! $workspace) {
            return;
        }

        if (($session['mode'] ?? null) === 'subscription' && filled($session['subscription'] ?? null)) {
            $this->refreshSubscription((string) $session['subscription']);

            return;
        }

        if (($session['mode'] ?? null) !== 'payment' || ($session['payment_status'] ?? null) !== 'paid' || ($session['metadata']['kind'] ?? null) !== 'credits') {
            return;
        }

        $packKey = (string) ($session['metadata']['pack'] ?? '');
        $pack = config('billing.credit_packs.'.$packKey);
        if (! is_array($pack) || (int) ($session['amount_total'] ?? 0) < $pack['amount'] || strtolower((string) ($session['currency'] ?? '')) !== config('billing.currency')) {
            Log::warning('Stripe credit pack checkout did not match a pack.', ['session' => $session['id'] ?? null, 'pack' => $packKey]);

            return;
        }

        $payment = (string) ($session['payment_intent'] ?? $session['id']);
        // A refund that arrived first leaves a marker; then there is nothing to grant.
        $credits = fn () => WorkspaceCredit::query()->where('idempotency_key', 'stripe:refund:'.$payment)->exists() ? null : $pack['credits'];
        $this->ledger($workspace, $credits, 'credit_pack_purchase', $pack['credits'].' credits purchased', 'stripe:payment:'.$payment, [
            'checkout_session' => $session['id'] ?? null,
            'payment_intent' => $session['payment_intent'] ?? null,
            'amount' => $session['amount_total'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $invoice */
    private function invoicePaid(array $invoice): void
    {
        $subscriptionId = $this->invoiceSubscriptionId($invoice);
        if (! $subscriptionId) {
            return;
        }
        $this->refreshSubscription($subscriptionId);

        // Credits come with each new billing cycle, never with mid-cycle proration invoices.
        if (! in_array($invoice['billing_reason'] ?? null, ['subscription_create', 'subscription_cycle'], true)) {
            return;
        }
        $workspace = $this->workspaceFor((string) ($invoice['customer'] ?? ''), Arr::get($invoice, 'subscription_details.metadata.workspace_id', Arr::get($invoice, 'parent.subscription_details.metadata.workspace_id')));
        if (! $workspace) {
            return;
        }

        $credits = (int) config('billing.plan.credits');
        $this->ledger($workspace, $credits, 'plan_allocation', 'Monthly plan credits', 'stripe:invoice:'.$invoice['id'], [
            'invoice' => $invoice['id'],
            'subscription' => $subscriptionId,
            'amount_paid' => $invoice['amount_paid'] ?? null,
            'period_end' => Arr::get($invoice, 'lines.data.0.period.end', $invoice['period_end'] ?? null),
        ]);
    }

    /** A fully refunded credit pack takes its credits back. */
    private function chargeRefunded(array $charge): void
    {
        if (! ($charge['refunded'] ?? false) || ! filled($charge['payment_intent'] ?? null)) {
            return;
        }
        $grantKey = 'stripe:payment:'.$charge['payment_intent'];
        $workspace = WorkspaceSubscription::query()->where('stripe_customer_id', (string) ($charge['customer'] ?? ''))->first()?->workspace
            ?? WorkspaceCredit::query()->where('idempotency_key', $grantKey)->first()?->workspace;
        if (! $workspace) {
            return;
        }

        // Decided under the workspace lock, like the grant, so a refund racing its purchase can't miss it.
        // With no grant yet this writes a zero marker, and the late purchase then grants nothing.
        $this->ledger($workspace, fn () => -(int) WorkspaceCredit::query()->where('idempotency_key', $grantKey)->value('amount'),
            'credit_pack_refund', 'Credit pack refunded', 'stripe:refund:'.$charge['payment_intent'], [
                'charge' => $charge['id'] ?? null,
                'payment_intent' => $charge['payment_intent'],
            ]);
    }

    /** Re-reads the subscription from Stripe and stores its current state on the workspace. */
    public function refreshSubscription(string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }
        $subscription = $this->stripe->get('subscriptions/'.$subscriptionId);
        $workspace = $this->workspaceFor((string) ($subscription['customer'] ?? ''), $subscription['metadata']['workspace_id'] ?? null);
        if (! $workspace) {
            return;
        }

        DB::transaction(function () use ($workspace, $subscriptionId): void {
            $row = WorkspaceSubscription::query()->lockForUpdate()->firstOrCreate(['workspace_id' => $workspace->id]);
            // Read again under the lock, so a slower request can't write back an older state.
            $subscription = $this->stripe->get('subscriptions/'.$subscriptionId);
            $replacing = $row->stripe_subscription_id && $row->stripe_subscription_id !== $subscription['id'];

            // A newer subscription in good standing is not overwritten by an older one ending.
            if ($replacing && $row->isSubscribed() && ! in_array($subscription['status'] ?? null, WorkspaceSubscription::GOOD_STANDING, true)) {
                return;
            }
            // A restart whose first payment hasn't gone through keeps the ended plan's lock until it does.
            if ($replacing && ($subscription['status'] ?? null) === 'incomplete') {
                return;
            }

            $periodEnd = $subscription['current_period_end'] ?? Arr::get($subscription, 'items.data.0.current_period_end');
            $row->update([
                'stripe_customer_id' => $row->stripe_customer_id ?: ($subscription['customer'] ?? null),
                'stripe_subscription_id' => $subscription['id'],
                'stripe_price_id' => Arr::get($subscription, 'items.data.0.price.id'),
                'status' => $subscription['status'] ?? null,
                'current_period_ends_at' => $periodEnd ? CarbonImmutable::createFromTimestamp($periodEnd) : null,
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false) || filled($subscription['cancel_at'] ?? null),
                // When service actually stopped: ended_at, not the earlier moment a cancellation was requested.
                'canceled_at' => ($endedAt = $subscription['ended_at'] ?? null) ? CarbonImmutable::createFromTimestamp($endedAt) : null,
                'stripe_updated_at' => now(),
            ]);
        });
    }

    private function invoiceSubscriptionId(array $invoice): ?string
    {
        $id = $invoice['subscription'] ?? Arr::get($invoice, 'parent.subscription_details.subscription');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The workspace a Stripe customer belongs to; metadata is only trusted when it agrees. */
    private function workspaceFor(string $customerId, mixed $workspaceId): ?Workspace
    {
        $row = $customerId !== '' ? WorkspaceSubscription::query()->where('stripe_customer_id', $customerId)->first() : null;
        if ($row) {
            return $row->workspace;
        }
        if ($customerId !== '' && is_numeric($workspaceId) && ($workspace = Workspace::query()->find((int) $workspaceId))) {
            $existing = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->value('stripe_customer_id');
            if ($existing === null) {
                WorkspaceSubscription::query()->updateOrCreate(['workspace_id' => $workspace->id], ['stripe_customer_id' => $customerId]);

                return $workspace;
            }
        }

        Log::warning('Stripe event for an unknown customer.', ['customer' => $customerId, 'workspace_id' => $workspaceId]);

        return null;
    }

    /** @param int|callable(): ?int $amount decided under the workspace lock when callable; null writes nothing */
    private function ledger(Workspace $workspace, int|callable $amount, string $event, string $description, string $key, array $metadata): void
    {
        try {
            DB::transaction(function () use ($workspace, $amount, $event, $description, $key, $metadata): void {
                Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
                if (WorkspaceCredit::query()->where('idempotency_key', $key)->exists()) {
                    return;
                }
                $amount = is_callable($amount) ? $amount() : $amount;
                if ($amount === null) {
                    return;
                }
                $workspace->credits()->create([
                    'amount' => $amount,
                    'event' => $event,
                    'description' => $description,
                    'idempotency_key' => $key,
                    'metadata' => array_filter($metadata, fn ($value) => $value !== null),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event already wrote this row.
        }
    }
}
