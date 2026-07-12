<?php

namespace App\Services;

use App\Models\StripeWebhookEvent;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\StripeClient;

class StripeBillingService
{
    public function checkoutUrl(Workspace $workspace, string $successUrl, string $cancelUrl): string
    {
        $subscription = $workspace->subscription()->firstOrCreate([]);
        $customer = $subscription->stripe_customer_id ?: $this->stripe()->customers->create(['metadata' => ['workspace_id' => (string) $workspace->id]])->id;
        $subscription->update(['stripe_customer_id' => $customer]);
        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => [['price' => $this->priceId(), 'quantity' => 1]],
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => ['workspace_id' => (string) $workspace->id],
        ]);

        return $session->url;
    }

    public function portalUrl(Workspace $workspace, string $returnUrl): string
    {
        $customer = $workspace->subscription?->stripe_customer_id ?: throw new RuntimeException('No Stripe customer exists for this workspace.');

        return $this->stripe()->billingPortal->sessions->create(['customer' => $customer, 'return_url' => $returnUrl])->url;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret')) && filled(config('services.stripe.price_id'));
    }

    public function recordEvent(string $eventId, string $type, array $payload): void
    {
        DB::transaction(function () use ($eventId, $type, $payload): void {
            $event = StripeWebhookEvent::firstOrCreate(['stripe_event_id' => $eventId], ['type' => $type, 'payload' => $payload]);
            if ($event->processed_at) {
                return;
            }
            $object = data_get($payload, 'data.object', []);
            $workspaceId = data_get($object, 'metadata.workspace_id');
            $subscriptionId = $this->subscriptionId($type, $object);
            $workspace = $workspaceId ? Workspace::lockForUpdate()->find($workspaceId) : null;
            $record = $workspace
                ? $workspace->subscription()->firstOrCreate([])
                : WorkspaceSubscription::query()->where('stripe_subscription_id', $subscriptionId)->lockForUpdate()->first();

            if ($record && $this->shouldApplySubscriptionState($record, $payload)) {
                $attributes = $this->subscriptionAttributes($type, $object, $subscriptionId, $payload);
                $record->update($attributes);
            }

            if ($record && $type === 'invoice.paid') {
                $invoiceId = data_get($object, 'id') ?: $eventId;
                $record->workspace->credits()->firstOrCreate(
                    ['stripe_invoice_id' => $invoiceId],
                    [
                        'amount' => config('campaigns.monthly_credits'),
                        'event' => 'billing_cycle_allocation',
                        'description' => "Stripe invoice {$invoiceId}",
                    ],
                );
            }
            $event->update(['processed_at' => now()]);
        });
    }

    private function subscriptionId(string $type, array $object): ?string
    {
        return str_starts_with($type, 'customer.subscription.')
            ? data_get($object, 'id')
            : data_get($object, 'subscription');
    }

    private function shouldApplySubscriptionState(WorkspaceSubscription $subscription, array $payload): bool
    {
        $occurredAt = $this->timestamp(data_get($payload, 'created'));

        return ! $occurredAt || ! $subscription->stripe_updated_at || $occurredAt->gte($subscription->stripe_updated_at);
    }

    private function subscriptionAttributes(string $type, array $object, ?string $subscriptionId, array $payload): array
    {
        return [
            'stripe_customer_id' => data_get($object, 'customer'),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => $this->priceIdFrom($object),
            'status' => $this->statusFor($type, $object),
            'current_period_ends_at' => $this->timestamp(data_get($object, 'current_period_end')),
            'canceled_at' => $type === 'customer.subscription.deleted' ? now() : null,
            'stripe_updated_at' => $this->timestamp(data_get($payload, 'created')),
        ];
    }

    private function statusFor(string $type, array $object): string
    {
        return match ($type) {
            'checkout.session.completed' => data_get($object, 'payment_status') === 'paid' ? 'active' : 'incomplete',
            'invoice.paid' => 'active',
            'invoice.payment_failed' => 'past_due',
            default => data_get($object, 'status', 'inactive'),
        };
    }

    private function priceIdFrom(array $object): ?string
    {
        return data_get($object, 'items.data.0.price.id') ?: config('services.stripe.price_id');
    }

    private function timestamp(mixed $value): mixed
    {
        return is_numeric($value) ? now()->setTimestamp((int) $value) : null;
    }

    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret') ?: throw new RuntimeException('Stripe is not configured.'));
    }

    private function priceId(): string
    {
        return config('services.stripe.price_id') ?: throw new RuntimeException('STRIPE_BETA_PRICE_ID is not configured.');
    }
}
