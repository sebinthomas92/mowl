<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\StripeBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class BillingController extends Controller
{
    public function checkout(Request $request, StripeBillingService $billing): RedirectResponse
    {
        $workspace = $this->ownerWorkspace($request);
        abort_unless($billing->isConfigured(), 503, 'Billing is not configured.');

        return redirect()->away($billing->checkoutUrl($workspace, route('billing.success'), route('usage.index')));
    }

    public function portal(Request $request, StripeBillingService $billing): RedirectResponse
    {
        $workspace = $this->ownerWorkspace($request);

        return redirect()->away($billing->portalUrl($workspace, route('usage.index')));
    }

    public function success(): RedirectResponse
    {
        return redirect()->route('usage.index')->with('status', 'Checkout is complete. Your workspace access will update when Stripe confirms payment.');
    }

    public function webhook(Request $request, StripeBillingService $billing): Response
    {
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), config('services.stripe.webhook_secret') ?: throw new \RuntimeException('Stripe webhook is not configured.'));
        } catch (\UnexpectedValueException|\JsonException|SignatureVerificationException $exception) {
            return response('Invalid Stripe webhook.', 400);
        }

        $billing->recordEvent($event->id, $event->type, json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR));

        return response('', 200);
    }

    private function ownerWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->session()->get('current_workspace_id');

        return $request->user()->workspaces()
            ->whereKey($workspaceId)
            ->wherePivot('role', 'owner')
            ->firstOrFail();
    }
}
