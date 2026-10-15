<?php

namespace App\Http\Controllers;

use App\Models\StripeWebhookEvent;
use App\Models\WorkspaceSubscription;
use App\Services\Billing\StripeWebhookHandler;
use App\Services\Billing\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Receives signed Stripe events. Each event is processed once; a failure returns 500 so Stripe retries. */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): Response
    {
        $payload = $request->getContent();
        if (! WebhookSignature::valid($payload, $request->header('Stripe-Signature'), (string) config('billing.stripe.webhook_secret'), (int) config('billing.stripe.tolerance'))) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('Invalid event.', 400);
        }
        if (! WorkspaceSubscription::available()) {
            return response('Billing is not ready yet.', 503);
        }

        $record = StripeWebhookEvent::query()->createOrFirst(
            ['stripe_event_id' => $event['id']],
            ['type' => $event['type'], 'payload' => $event],
        );
        if ($record->processed_at) {
            return response('Already processed.', 200);
        }

        $handler->handle($event);
        $record->update(['processed_at' => now()]);

        return response('OK', 200);
    }
}
