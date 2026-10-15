<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Services\Billing\BillingCheckout;
use App\Services\Billing\StripeClient;
use App\Services\Billing\StripeException;
use App\Services\Billing\StripeWebhookHandler;
use App\Support\Membership;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class BillingIndex extends Component
{
    use InteractsWithWorkspace;

    public ?string $notice = null;

    public function mount(StripeClient $stripe, StripeWebhookHandler $handler): void
    {
        // Stripe returns here with the Checkout session id, or "cancelled".
        $checkout = (string) request()->query('checkout', '');
        if ($checkout === 'cancelled') {
            $this->notice = 'Checkout was cancelled. Nothing was charged.';
        } elseif (preg_match('/^cs_[A-Za-z0-9_]+$/', $checkout)) {
            $this->notice = 'Payment received. Your plan and credits are below.';
            $this->reconcile($stripe, $handler, $checkout);
        }
    }

    public function startPlan(BillingCheckout $billing): void
    {
        $workspace = $this->ownerWorkspace();
        $this->go(fn () => $billing->planCheckoutUrl($workspace, auth()->user()));
    }

    public function buyCredits(BillingCheckout $billing, string $pack): void
    {
        $workspace = $this->ownerWorkspace();
        $this->go(fn () => $billing->creditPackCheckoutUrl($workspace, auth()->user(), $pack));
    }

    public function openPortal(BillingCheckout $billing): void
    {
        $workspace = $this->ownerWorkspace();
        $this->go(fn () => $billing->portalUrl($workspace));
    }

    /**
     * Applies a finished Checkout straight away, so the page is right even before the webhook lands.
     * The webhook does the same work later and finds nothing left to do.
     */
    private function reconcile(StripeClient $stripe, StripeWebhookHandler $handler, string $sessionId): void
    {
        $workspace = $this->currentWorkspace();
        if (! $stripe->configured() || ! WorkspaceSubscription::available()) {
            return;
        }
        try {
            $session = $stripe->get('checkout/sessions/'.$sessionId);
            if ((string) ($session['metadata']['workspace_id'] ?? '') === (string) $workspace->id
                && ($session['customer'] ?? null) === $workspace->billingSubscription()?->stripe_customer_id) {
                $handler->checkoutCompleted($session);
            }
        } catch (StripeException $exception) {
            Log::warning('Could not reconcile a Stripe Checkout session.', ['session' => $sessionId, 'error' => $exception->getMessage()]);
        }
    }

    private function go(callable $url): void
    {
        try {
            $this->redirect($url());
        } catch (StripeException $exception) {
            Log::error('Stripe request failed.', ['error' => $exception->getMessage(), 'code' => $exception->stripeCode]);
            $this->addError('billing', 'Stripe could not start this right now: '.$exception->getMessage());
        }
    }

    private function ownerWorkspace(): Workspace
    {
        $workspace = $this->currentWorkspace();
        abort_unless(Membership::of(auth()->user(), $workspace)?->isOwner(), 403);
        abort_unless(app(StripeClient::class)->readyForCheckout() && WorkspaceSubscription::available(), 422);

        return $workspace;
    }

    public function render(StripeClient $stripe)
    {
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);
        $subscription = $workspace->billingSubscription();

        return view('livewire.billing-index', [
            'workspace' => $workspace,
            'isOwner' => (bool) $membership?->isOwner(),
            'owner' => $workspace->users()->wherePivot('role', 'owner')->first(),
            'ready' => $stripe->readyForCheckout() && WorkspaceSubscription::available(),
            'testMode' => $stripe->testMode(),
            'subscription' => $subscription,
            'subscribed' => (bool) $subscription?->isSubscribed(),
            'locked' => (bool) $subscription?->isLocked(),
            'plan' => config('billing.plan'),
            'packs' => config('billing.credit_packs'),
            'creditBalance' => $workspace->creditBalance(),
            'spendableCredits' => $workspace->spendableCredits(),
            'billingEvents' => $workspace->credits()
                ->whereIn('event', ['beta_allocation', 'plan_allocation', 'credit_pack_purchase', 'credit_pack_refund', 'concierge_adjustment'])
                ->latest()->limit(12)->get(),
        ])->layout('components.layouts.app');
    }
}
