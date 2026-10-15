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

        return $this->checkoutUrl($workspace, $owner, [
            'mode' => 'subscription',
            'line_items' => [$lineItem],
            'allow_promotion_codes' => 'true',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'plan'],
            'subscription_data' => ['metadata' => ['workspace_id' => (string) $workspace->id]],
        ]);
    }

    public function creditPackCheckoutUrl(Workspace $workspace, User $owner, string $packKey): string
    {
        $pack = config('billing.credit_packs.'.$packKey);
        if (! is_array($pack)) {
            throw ValidationException::withMessages(['billing' => 'Choose one of the credit packs.']);
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
