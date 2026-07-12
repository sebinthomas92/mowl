<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoice_allocates_monthly_credits_once(): void
    {
        $workspace = Workspace::create(['name' => 'Paid Agency']);
        $workspace->users()->attach(User::factory()->create(), ['role' => 'owner']);
        $payload = ['data' => ['object' => ['id' => 'in_test', 'metadata' => ['workspace_id' => (string) $workspace->id], 'customer' => 'cus_test', 'subscription' => 'sub_test']]];

        app(StripeBillingService::class)->recordEvent('evt_invoice_paid', 'invoice.paid', $payload);
        app(StripeBillingService::class)->recordEvent('evt_invoice_paid_replayed_with_a_new_event', 'invoice.paid', $payload);

        $this->assertSame(50, $workspace->creditBalance());
        $this->assertSame('active', $workspace->fresh()->subscription->status);
        $this->assertDatabaseCount('stripe_webhook_events', 2);
        $this->assertDatabaseCount('workspace_credits', 1);
    }

    public function test_subscription_events_use_the_subscription_object_id_and_preserve_the_period(): void
    {
        $workspace = Workspace::create(['name' => 'Paid Agency']);
        $periodEnd = now()->addMonth()->timestamp;
        $payload = ['data' => ['object' => [
            'id' => 'sub_active',
            'customer' => 'cus_active',
            'status' => 'active',
            'current_period_end' => $periodEnd,
            'metadata' => ['workspace_id' => (string) $workspace->id],
        ]]];

        app(StripeBillingService::class)->recordEvent('evt_subscription_updated', 'customer.subscription.updated', $payload);

        $subscription = $workspace->fresh()->subscription;
        $this->assertSame('sub_active', $subscription->stripe_subscription_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame($periodEnd, $subscription->current_period_ends_at->timestamp);
    }

    public function test_an_out_of_order_subscription_event_cannot_restore_cancelled_access(): void
    {
        $workspace = Workspace::create(['name' => 'Paid Agency']);
        $service = app(StripeBillingService::class);

        $service->recordEvent('evt_subscription_deleted', 'customer.subscription.deleted', [
            'created' => 200,
            'data' => ['object' => [
                'id' => 'sub_ordered',
                'customer' => 'cus_ordered',
                'status' => 'canceled',
                'metadata' => ['workspace_id' => (string) $workspace->id],
            ]],
        ]);
        $service->recordEvent('evt_subscription_old_update', 'customer.subscription.updated', [
            'created' => 100,
            'data' => ['object' => [
                'id' => 'sub_ordered',
                'customer' => 'cus_ordered',
                'status' => 'active',
                'metadata' => ['workspace_id' => (string) $workspace->id],
            ]],
        ]);

        $this->assertSame('canceled', $workspace->fresh()->subscription->status);
    }

    public function test_a_failed_renewal_marks_the_known_subscription_past_due(): void
    {
        $workspace = Workspace::create(['name' => 'Paid Agency']);
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_due', 'stripe_subscription_id' => 'sub_due', 'status' => 'active']);

        app(StripeBillingService::class)->recordEvent('evt_invoice_failed', 'invoice.payment_failed', [
            'data' => ['object' => ['id' => 'in_failed', 'customer' => 'cus_due', 'subscription' => 'sub_due']],
        ]);

        $this->assertSame('past_due', $workspace->fresh()->subscription->status);
    }

    public function test_a_signed_event_for_a_deleted_workspace_is_safely_recorded(): void
    {
        app(StripeBillingService::class)->recordEvent('evt_deleted_workspace', 'checkout.session.completed', [
            'data' => ['object' => ['metadata' => ['workspace_id' => '999999'], 'subscription' => 'sub_deleted']],
        ]);

        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_deleted_workspace']);
    }

    public function test_invoice_without_workspace_metadata_uses_its_known_subscription(): void
    {
        $workspace = Workspace::create(['name' => 'Paid Agency']);
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_known', 'stripe_subscription_id' => 'sub_known', 'status' => 'active']);

        app(StripeBillingService::class)->recordEvent('evt_known_invoice', 'invoice.paid', [
            'data' => ['object' => ['id' => 'in_known', 'customer' => 'cus_known', 'subscription' => 'sub_known']],
        ]);

        $this->assertSame(50, $workspace->fresh()->creditBalance());
        $this->assertDatabaseHas('workspace_credits', ['stripe_invoice_id' => 'in_known']);
    }

    public function test_active_subscription_is_required_when_billing_enforcement_is_enabled(): void
    {
        config(['billing.enforce' => true]);
        $workspace = Workspace::create(['name' => 'Read only Agency']);

        $this->assertFalse($workspace->hasPaidAccess());

        $workspace->subscription()->create(['status' => 'active']);

        $this->assertTrue($workspace->fresh()->hasPaidAccess());
    }
}
