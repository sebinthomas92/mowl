<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Starts Stripe Checkout for the plan or a credit pack, and opens the customer portal. */
class BillingCheckout
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function planCheckoutUrl(Workspace $workspace, User $owner): string
    {
        $subscription = $workspace->billingSubscription();
        if ($subscription?->isSubscribed()) {
            $this->revalidate($subscription);
        }
        if ($workspace->billingSubscription()?->isSubscribed()) {
            throw ValidationException::withMessages(['billing' => 'This workspace already has an active plan. Manage it in the billing portal.']);
        }

        $plan = config('billing.plan');
        $lineItem = filled(config('billing.stripe.plan_price'))
            ? ['price' => config('billing.stripe.plan_price'), 'quantity' => 1]
            : ['quantity' => 1, 'price_data' => [
                'currency' => config('billing.currency'),
                'unit_amount' => $plan['amount'],
                'recurring' => ['interval' => $plan['interval']],
                'product_data' => ['name' => $plan['name'], 'description' => $plan['credits'].' launch pack credits every month'],
            ]];

        // Outlasts the worst case of the Stripe calls below (each times out at 20s).
        $lock = Cache::lock('billing.plan-checkout.'.$workspace->id, 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['billing' => 'A checkout is already being opened for this workspace.']);
        }

        try {
            $this->ensureNoLiveSubscription($workspace);
            $this->expireOpenPlanSessions($workspace);

            return $this->checkoutUrl($workspace, $owner, [
                'mode' => 'subscription',
                'line_items' => [$lineItem],
                'allow_promotion_codes' => 'true',
                'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'plan'],
                'subscription_data' => ['metadata' => ['workspace_id' => (string) $workspace->id]],
            ]);
        } finally {
            $lock->release();
        }
    }

    /** A row that says "active" may have missed its final webhook, or point at a customer that is gone. */
    private function revalidate(WorkspaceSubscription $subscription): void
    {
        if (! $subscription->stripe_subscription_id) {
            return;
        }
        try {
            app(StripeWebhookHandler::class)->refreshSubscription($subscription->stripe_subscription_id);
        } catch (StripeException $exception) {
            if ($exception->stripeCode !== 'resource_missing') {
                throw $exception;
            }
            // Only the subscription is known to be gone; the customer is checked separately before checkout.
            // The workspace had a plan, so it stays locked as ended until a new one starts.
            $subscription->update(['stripe_subscription_id' => null, 'status' => 'canceled', 'cancel_at_period_end' => false, 'canceled_at' => now()]);
        }
    }

    /**
     * Asks Stripe, not just the local row, so a plan paid in another tab whose webhook hasn't landed
     * yet can't be bought twice.
     */
    private function ensureNoLiveSubscription(Workspace $workspace): void
    {
        $customer = $workspace->subscription()->value('stripe_customer_id');
        if (! $customer) {
            return;
        }
        try {
            $subscriptions = $this->stripe->get('subscriptions', ['customer' => $customer, 'status' => 'all', 'limit' => 20]);
        } catch (StripeException $exception) {
            // A customer from another Stripe mode or account: forget it, and checkout makes a fresh one.
            if ($exception->stripeCode !== 'resource_missing') {
                throw $exception;
            }
            $workspace->subscription()->update(['stripe_customer_id' => null]);

            return;
        }
        foreach ($subscriptions['data'] ?? [] as $subscription) {
            // Only this workspace's plan; anything else on the customer is left alone.
            if ((string) ($subscription['metadata']['workspace_id'] ?? '') !== (string) $workspace->id) {
                continue;
            }
            // An unpaid first attempt, or an unpaid or paused plan that could later be paid or resumed,
            // is cancelled so it can never run alongside the new one.
            if (in_array($subscription['status'] ?? null, ['incomplete', 'unpaid', 'paused'], true)) {
                $this->stripe->delete('subscriptions/'.$subscription['id']);

                continue;
            }
            if (in_array($subscription['status'] ?? null, WorkspaceSubscription::GOOD_STANDING, true)) {
                app(StripeWebhookHandler::class)->refreshSubscription((string) $subscription['id']);
                throw ValidationException::withMessages(['billing' => 'This workspace already has a plan in Stripe. Refresh the page to see it, or manage it in the billing portal.']);
            }
        }
    }

    /** Expires earlier plan checkouts still open, so two tabs can never start two subscriptions. */
    private function expireOpenPlanSessions(Workspace $workspace): void
    {
        $customer = $workspace->subscription()->value('stripe_customer_id');
        if (! $customer) {
            return;
        }
        $sessions = $this->stripe->get('checkout/sessions', ['customer' => $customer, 'status' => 'open', 'limit' => 20]);
        foreach ($sessions['data'] ?? [] as $session) {
            if (($session['mode'] ?? null) === 'subscription') {
                $this->stripe->post('checkout/sessions/'.$session['id'].'/expire');
            }
        }
    }

    public function creditPackCheckoutUrl(Workspace $workspace, User $owner, string $packKey): string
    {
        $pack = config('billing.credit_packs.'.$packKey);
        if (! is_array($pack)) {
            throw ValidationException::withMessages(['billing' => 'Choose one of the credit packs.']);
        }
        $subscription = $workspace->billingSubscription();
        if ($subscription?->isSubscribed()) {
            $this->revalidate($subscription);
        }
        if (! $workspace->billingSubscription()?->isSubscribed()) {
            throw ValidationException::withMessages(['billing' => 'Start the plan before buying extra credits.']);
        }

        $metadata = ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => $packKey, 'credits' => (string) $pack['credits']];

        return $this->checkoutUrl($workspace, $owner, [
            'mode' => 'payment',
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => config('billing.currency'),
                'unit_amount' => $pack['amount'],
                'product_data' => ['name' => $pack['credits'].' launch pack credits'],
            ]]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'invoice_creation' => ['enabled' => 'true'],
        ]);
    }

    public function portalUrl(Workspace $workspace): string
    {
        $customer = $workspace->billingSubscription()?->stripe_customer_id;
        if (! $customer) {
            throw ValidationException::withMessages(['billing' => 'Start the plan first; the billing portal opens once there is a customer record.']);
        }

        $session = $this->stripe->post('billing_portal/sessions', [
            'customer' => $customer,
            'return_url' => route('billing.index'),
            'configuration' => $this->portalConfiguration(),
        ]);

        return (string) $session['url'];
    }

    private function checkoutUrl(Workspace $workspace, User $owner, array $params): string
    {
        $params += [
            'client_reference_id' => (string) $workspace->id,
            'success_url' => route('billing.index').'?checkout={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.index').'?checkout=cancelled',
        ];

        try {
            $session = $this->stripe->post('checkout/sessions', $params + ['customer' => $this->customerId($workspace, $owner)]);
        } catch (StripeException $exception) {
            // A customer from another Stripe mode or account: forget it and make a fresh one once.
            if ($exception->stripeCode !== 'resource_missing' || ! str_contains($exception->getMessage(), 'customer')) {
                throw $exception;
            }
            $workspace->subscription()->update(['stripe_customer_id' => null]);
            $session = $this->stripe->post('checkout/sessions', $params + ['customer' => $this->customerId($workspace, $owner)]);
        }

        return (string) $session['url'];
    }

    /** The workspace's Stripe customer, created once and kept on its subscription row. */
    private function customerId(Workspace $workspace, User $owner): string
    {
        $existing = $workspace->subscription()->value('stripe_customer_id');
        if ($existing) {
            return $existing;
        }

        $customer = $this->stripe->post('customers', [
            'email' => $owner->email,
            'name' => $workspace->name,
            'metadata' => ['workspace_id' => (string) $workspace->id],
        ], 'mowl-customer-'.$workspace->id.'-'.substr(hash('sha256', (string) config('billing.stripe.secret')), 0, 8).'-'.now()->format('Ymd'));

        return DB::transaction(function () use ($workspace, $customer): string {
            $row = WorkspaceSubscription::query()->lockForUpdate()->firstOrCreate(['workspace_id' => $workspace->id]);
            if (! $row->stripe_customer_id) {
                $row->update(['stripe_customer_id' => $customer['id']]);
            }

            return $row->stripe_customer_id;
        });
    }

    private function portalConfiguration(): string
    {
        if (filled(config('billing.stripe.portal_configuration'))) {
            return config('billing.stripe.portal_configuration');
        }

        $cacheKey = 'billing.portal_configuration.'.substr(hash('sha256', (string) config('billing.stripe.secret')), 0, 16);

        return Cache::rememberForever($cacheKey, fn () => (string) $this->stripe->post('billing_portal/configurations', [
            'business_profile' => ['headline' => 'Marketing Owl billing'],
            'features' => [
                'customer_update' => ['enabled' => 'true', 'allowed_updates' => ['email', 'address', 'name']],
                'invoice_history' => ['enabled' => 'true'],
                'payment_method_update' => ['enabled' => 'true'],
                'subscription_cancel' => ['enabled' => 'true', 'mode' => 'at_period_end'],
            ],
        ])['id']);
    }
}
