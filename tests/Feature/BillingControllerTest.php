<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_cannot_start_checkout_for_the_current_workspace(): void
    {
        $workspace = Workspace::create(['name' => 'Agency']);
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('billing.checkout'))
            ->assertNotFound();
    }

    public function test_an_invalid_stripe_signature_is_rejected_without_recording_an_event(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->call('POST', route('stripe.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
        ], '{"id":"evt_invalid"}')
            ->assertBadRequest();

        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_a_valid_signed_webhook_is_processed_without_csrf(): void
    {
        $workspace = Workspace::create(['name' => 'Agency']);
        $secret = 'whsec_test';
        config(['services.stripe.webhook_secret' => $secret]);
        $payload = json_encode([
            'id' => 'evt_signed_invoice',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_signed',
                'metadata' => ['workspace_id' => (string) $workspace->id],
                'customer' => 'cus_signed',
                'subscription' => 'sub_signed',
            ]]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $this->call('POST', route('stripe.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)
            ->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_signed_invoice']);
        $this->assertSame(50, $workspace->fresh()->creditBalance());
    }
}
