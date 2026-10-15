<?php

namespace Tests\Feature;

use App\Livewire\BillingIndex;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.stripe.secret' => 'sk_test_123',
            'billing.stripe.webhook_secret' => self::SECRET,
            'billing.stripe.portal_configuration' => null,
        ]);
    }

    public function test_owner_starts_plan_checkout_with_a_new_customer(): void
    {
        [$owner, $workspace] = $this->workspaceUser();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)
            ->call('startPlan')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        $this->assertSame('cus_1', $workspace->subscription()->value('stripe_customer_id'));
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/checkout/sessions')) {
                return false;
            }
            $data = $request->data();

            return $data['mode'] === 'subscription'
                && $data['customer'] === 'cus_1'
                && $data['line_items'][0]['price_data']['unit_amount'] === 12900
                && $data['line_items'][0]['price_data']['recurring']['interval'] === 'month'
                && $data['subscription_data']['metadata']['workspace_id'] === (string) $request->data()['client_reference_id'];
        });
    }

    public function test_only_the_owner_can_start_checkout_and_reviewers_cannot_see_billing(): void
    {
        [, $workspace] = $this->workspaceUser();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $reviewer = User::factory()->create();
        $workspace->users()->attach($reviewer, ['role' => 'reviewer']);
        Http::fake();

        Livewire::actingAs($member)->test(BillingIndex::class)->call('startPlan')->assertForbidden();
        $this->actingAs($reviewer)->get('/billing')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->postJson('/stripe/webhook', ['id' => 'evt_1', 'type' => 'invoice.paid'], ['Stripe-Signature' => 't='.time().',v1=bad'])
            ->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_paid_cycle_grants_credits_exactly_once_and_activates_the_plan(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $invoice = ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 12900,
            'parent' => ['subscription_details' => ['subscription' => 'sub_1', 'metadata' => ['workspace_id' => (string) $workspace->id]]]];

        $this->webhook('evt_1', 'invoice.paid', $invoice)->assertOk();
        $this->webhook('evt_1', 'invoice.paid', $invoice)->assertOk();
        // The same invoice under a new event id (Stripe resends) still grants once.
        $this->webhook('evt_2', 'invoice.paid', $invoice)->assertOk();

        $this->assertSame(1, $workspace->credits()->where('event', 'plan_allocation')->count());
        $this->assertSame(50, $workspace->creditBalance());
        $subscription = $workspace->subscription()->first();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('sub_1', $subscription->stripe_subscription_id);
        $this->assertNotNull($subscription->current_period_ends_at);
    }

    public function test_proration_invoices_do_not_grant_credits(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->webhook('evt_1', 'invoice.paid', ['id' => 'in_2', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_update'])->assertOk();

        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_out_of_order_events_settle_on_stripes_current_state(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        // An old "incomplete" event arrives after the subscription became active: the stored state follows Stripe, not the event.
        $this->webhook('evt_9', 'customer.subscription.updated', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'incomplete', 'customer' => 'cus_1'])->assertOk();

        $this->assertSame('active', $workspace->subscription()->value('status'));
    }

    public function test_cancelled_plan_locks_generation_but_keeps_the_workspace(): void
    {
        [$owner, $workspace] = $this->subscribedWorkspace(status: 'canceled');
        $workspace->credits()->create(['amount' => 20, 'event' => 'plan_allocation', 'description' => 'Monthly plan credits']);

        $this->webhook('evt_3', 'customer.subscription.deleted', ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'canceled'])->assertOk();

        $workspace->refresh();
        $this->assertTrue($workspace->billingLocked());
        $this->assertSame(0, $workspace->spendableCredits());
        $this->assertSame(20, $workspace->creditBalance());
        $this->actingAs($owner)->get('/billing')->assertOk()->assertSee('The plan has ended')->assertSee('Restart the plan');
    }

    public function test_credit_pack_purchase_grants_once_and_a_full_refund_takes_it_back(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $session = ['id' => 'cs_9', 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'payment_intent' => 'pi_9', 'amount_total' => 5000, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_25', 'credits' => '25']];

        $this->webhook('evt_10', 'checkout.session.completed', $session)->assertOk();
        $this->webhook('evt_11', 'checkout.session.completed', $session)->assertOk();
        $this->assertSame(25, $workspace->creditBalance());

        $charge = ['id' => 'ch_9', 'payment_intent' => 'pi_9', 'invoice' => null, 'refunded' => true];
        $this->webhook('evt_12', 'charge.refunded', $charge)->assertOk();
        $this->webhook('evt_13', 'charge.refunded', $charge)->assertOk();
        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_a_credit_pack_with_a_wrong_amount_grants_nothing(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->webhook('evt_20', 'checkout.session.completed', ['id' => 'cs_x', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'payment_intent' => 'pi_x', 'amount_total' => 100, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_50']])->assertOk();

        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_events_for_another_workspaces_customer_cannot_be_redirected_by_metadata(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        [, $other] = $this->workspaceUser();
        $other->subscription()->create(['stripe_customer_id' => 'cus_other']);

        $this->webhook('evt_30', 'checkout.session.completed', ['id' => 'cs_y', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_unknown',
            'payment_intent' => 'pi_y', 'amount_total' => 2000, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $other->id, 'kind' => 'credits', 'pack' => 'credits_10']])->assertOk();

        $this->assertSame(0, $other->creditBalance());
        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_return_from_checkout_applies_the_session_before_the_webhook(): void
    {
        [$owner, $workspace] = $this->subscribedWorkspace(status: 'active');
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_5' => Http::response(['id' => 'cs_5', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1',
                'payment_intent' => 'pi_5', 'amount_total' => 2000, 'currency' => 'usd',
                'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_10']]),
        ]);

        $this->actingAs($owner)->get('/billing?checkout=cs_5')->assertOk()->assertSee('Payment received');
        $this->assertSame(10, $workspace->creditBalance());
    }

    public function test_credit_packs_need_an_active_plan(): void
    {
        [$owner] = $this->workspaceUser();
        Http::fake();

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('buyCredits', 'credits_10')->assertHasErrors('billing');
        Http::assertNothingSent();
    }

    public function test_a_restart_whose_first_payment_fails_stays_locked(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'canceled');
        $workspace->credits()->create(['amount' => 20, 'event' => 'plan_allocation', 'description' => 'Monthly plan credits']);
        $this->stripeSays('sub_2', 'incomplete', $workspace);

        $this->webhook('evt_40', 'customer.subscription.created', ['id' => 'sub_2', 'customer' => 'cus_1'])->assertOk();
        $this->assertTrue($workspace->refresh()->billingLocked());

        $this->stripeSays('sub_2', 'active', $workspace);
        $this->webhook('evt_41', 'customer.subscription.updated', ['id' => 'sub_2', 'customer' => 'cus_1'])->assertOk();
        $this->assertFalse($workspace->refresh()->billingLocked());
        $this->assertSame(20, $workspace->spendableCredits());
    }

    public function test_a_refund_that_arrives_before_the_purchase_grants_nothing(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->webhook('evt_50', 'charge.refunded', ['id' => 'ch_1', 'customer' => 'cus_1', 'payment_intent' => 'pi_1', 'invoice' => null, 'refunded' => true])->assertOk();
        $this->webhook('evt_51', 'checkout.session.completed', ['id' => 'cs_1', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'payment_intent' => 'pi_1', 'amount_total' => 2000, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_10']])->assertOk();

        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_checkout_stays_off_without_a_webhook_secret(): void
    {
        config(['billing.stripe.webhook_secret' => null]);
        [$owner] = $this->workspaceUser();
        Http::fake();

        $this->actingAs($owner)->get('/billing')->assertOk()->assertSee('Payments are not switched on yet');
        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_starting_the_plan_again_expires_the_earlier_open_checkout(): void
    {
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_1']);
        Http::fake([
            'api.stripe.com/v1/subscriptions?*' => Http::response(['data' => [['id' => 'sub_old', 'status' => 'incomplete', 'metadata' => ['workspace_id' => (string) $workspace->id]]]]),
            'api.stripe.com/v1/subscriptions/sub_old' => Http::response(['id' => 'sub_old', 'status' => 'canceled']),
            'api.stripe.com/v1/checkout/sessions/cs_old/expire' => Http::response(['id' => 'cs_old', 'status' => 'expired']),
            'api.stripe.com/v1/checkout/sessions?*' => Http::response(['data' => [['id' => 'cs_old', 'mode' => 'subscription'], ['id' => 'cs_pack', 'mode' => 'payment']]]),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_new']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout/sessions/cs_old/expire'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/subscriptions/sub_old'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'cs_pack/expire'));
    }

    public function test_a_plan_paid_in_another_tab_blocks_a_second_checkout(): void
    {
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_1']);
        $this->stripeSays('sub_1', 'active', $workspace);
        Http::fake([
            'api.stripe.com/v1/subscriptions?*' => Http::response(['data' => [['id' => 'sub_1', 'status' => 'active', 'metadata' => ['workspace_id' => (string) $workspace->id]]]]),
            'api.stripe.com/v1/subscriptions/sub_1' => Http::response($this->stripeSubscriptions['sub_1']),
            'api.stripe.com/v1/checkout/sessions*' => Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_new']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertHasErrors('billing')->assertNoRedirect();

        $this->assertSame('active', $workspace->subscription()->value('status'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'checkout/sessions'));
    }

    public function test_an_ended_plan_shows_when_service_stopped(): void
    {
        [$owner] = $this->subscribedWorkspace(status: 'canceled');

        $this->actingAs($owner)->get('/billing')->assertSee('Ended '.now()->format('M j, Y'));
    }

    public function test_a_refunded_plan_invoice_takes_its_credits_back(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->webhook('evt_60', 'invoice.paid', ['id' => 'in_9', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_cycle'])->assertOk();
        $this->assertSame(50, $workspace->creditBalance());

        $this->webhook('evt_61', 'charge.refunded', ['id' => 'ch_9', 'customer' => 'cus_1', 'payment_intent' => 'pi_inv', 'invoice' => 'in_9', 'refunded' => true])->assertOk();
        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_an_expired_first_attempt_keeps_beta_credits(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'incomplete_expired');
        $workspace->credits()->create(['amount' => 50, 'event' => 'beta_allocation', 'description' => 'Initial beta pack credits']);

        $this->assertFalse($workspace->refresh()->billingLocked());
        $this->assertSame(50, $workspace->spendableCredits());
    }

    public function test_a_stale_active_row_is_checked_with_stripe_before_blocking_checkout(): void
    {
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_gone', 'stripe_subscription_id' => 'sub_gone', 'status' => 'active']);
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_gone' => Http::response(['error' => ['message' => 'No such subscription', 'code' => 'resource_missing']], 404),
            'api.stripe.com/v1/subscriptions?*' => Http::response(['error' => ['message' => 'No such customer', 'code' => 'resource_missing']], 404),
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_new']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');
        $this->assertSame('cus_new', $workspace->subscription()->value('stripe_customer_id'));
        $this->assertTrue($workspace->refresh()->billingLocked());
    }

    public function test_an_unreachable_stripe_shows_an_error_instead_of_failing(): void
    {
        [$owner] = $this->workspaceUser();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertHasErrors('billing')->assertNoRedirect();
    }

    public function test_an_unconfirmed_checkout_return_does_not_claim_payment(): void
    {
        [$owner, $workspace] = $this->subscribedWorkspace(status: 'active');
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_6' => Http::response(['id' => 'cs_6', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'unpaid', 'customer' => 'cus_1',
                'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_10']]),
            'api.stripe.com/v1/checkout/sessions/cs_other' => Http::response(['id' => 'cs_other', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_x', 'metadata' => ['workspace_id' => '999']]),
        ]);

        $this->actingAs($owner)->get('/billing?checkout=cs_6')->assertOk()->assertDontSee('Payment received')->assertSee('still confirming');
        $this->actingAs($owner)->get('/billing?checkout=cs_other')->assertOk()->assertDontSee('Payment received');
        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_a_lost_dispute_takes_credits_back_and_a_won_one_returns_them(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->webhook('evt_70', 'checkout.session.completed', ['id' => 'cs_7', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'payment_intent' => 'pi_7', 'amount_total' => 2000, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_10']])->assertOk();
        $this->webhook('evt_71', 'invoice.paid', ['id' => 'in_7', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_cycle'])->assertOk();
        $this->assertSame(60, $workspace->creditBalance());
        Http::fake([
            'api.stripe.com/v1/charges/ch_pack' => Http::response(['id' => 'ch_pack', 'customer' => 'cus_1', 'payment_intent' => 'pi_7', 'invoice' => null]),
            'api.stripe.com/v1/charges/ch_plan' => Http::response(['id' => 'ch_plan', 'customer' => 'cus_1', 'payment_intent' => 'pi_plan', 'invoice' => 'in_7']),
        ]);

        $this->webhook('evt_72', 'charge.dispute.funds_withdrawn', ['id' => 'dp_1', 'charge' => 'ch_pack'])->assertOk();
        $this->webhook('evt_73', 'charge.dispute.funds_withdrawn', ['id' => 'dp_1', 'charge' => 'ch_pack'])->assertOk();
        $this->webhook('evt_74', 'charge.dispute.funds_withdrawn', ['id' => 'dp_2', 'charge' => 'ch_plan'])->assertOk();
        $this->assertSame(0, $workspace->creditBalance());

        $this->webhook('evt_75', 'charge.dispute.funds_reinstated', ['id' => 'dp_2', 'charge' => 'ch_plan'])->assertOk();
        $this->webhook('evt_76', 'charge.dispute.funds_reinstated', ['id' => 'dp_2', 'charge' => 'ch_plan'])->assertOk();
        $this->assertSame(50, $workspace->creditBalance());
    }

    public function test_restarting_cancels_an_unpaid_or_paused_plan_first(): void
    {
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_unpaid', 'status' => 'unpaid']);
        Http::fake([
            'api.stripe.com/v1/subscriptions?*' => Http::response(['data' => [
                ['id' => 'sub_unpaid', 'status' => 'unpaid', 'metadata' => ['workspace_id' => (string) $workspace->id]],
                ['id' => 'sub_paused', 'status' => 'paused', 'metadata' => ['workspace_id' => (string) $workspace->id]],
                ['id' => 'sub_other', 'status' => 'unpaid', 'metadata' => []],
            ]]),
            'api.stripe.com/v1/subscriptions/*' => Http::response(['id' => 'sub_x', 'status' => 'canceled']),
            'api.stripe.com/v1/checkout/sessions?*' => Http::response(['data' => []]),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_new']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/subscriptions/sub_unpaid'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/subscriptions/sub_paused'));
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/subscriptions/sub_other'));
    }

    public function test_a_customer_from_another_stripe_mode_is_replaced_on_restart(): void
    {
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_test', 'stripe_subscription_id' => 'sub_test', 'status' => 'canceled']);
        Http::fake([
            'api.stripe.com/v1/subscriptions?*' => Http::response(['error' => ['message' => 'No such customer', 'code' => 'resource_missing']], 404),
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_live']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_new']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('startPlan')->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');
        $this->assertSame('cus_live', $workspace->subscription()->value('stripe_customer_id'));
    }

    public function test_credit_packs_recheck_a_stale_active_plan_with_stripe(): void
    {
        [$owner, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->stripeSays('sub_1', 'canceled', $workspace);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('buyCredits', 'credits_10')->assertHasErrors('billing')->assertNoRedirect();
        $this->assertSame('canceled', $workspace->subscription()->value('status'));
    }

    public function test_the_portal_still_opens_without_a_webhook_secret(): void
    {
        config(['billing.stripe.webhook_secret' => null]);
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_1', 'status' => 'past_due']);
        Http::fake([
            'api.stripe.com/v1/billing_portal/configurations' => Http::response(['id' => 'bpc_1']),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/1']),
        ]);

        Livewire::actingAs($owner)->test(BillingIndex::class)->call('openPortal')->assertRedirect('https://billing.stripe.com/p/session/1');
    }

    public function test_a_refunded_pack_with_a_receipt_invoice_takes_its_credits_back(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        $this->webhook('evt_80', 'checkout.session.completed', ['id' => 'cs_8', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'payment_intent' => 'pi_8', 'amount_total' => 2000, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_10']])->assertOk();
        $this->assertSame(10, $workspace->creditBalance());

        $this->webhook('evt_81', 'charge.refunded', ['id' => 'ch_8', 'customer' => 'cus_1', 'payment_intent' => 'pi_8', 'invoice' => 'in_receipt', 'refunded' => true])->assertOk();
        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_a_dispute_before_the_purchase_lands_grants_nothing(): void
    {
        [, $workspace] = $this->subscribedWorkspace(status: 'active');
        Http::fake(['api.stripe.com/v1/charges/ch_early' => Http::response(['id' => 'ch_early', 'customer' => 'cus_1', 'payment_intent' => 'pi_early', 'invoice' => null])]);
        $this->webhook('evt_90', 'charge.dispute.funds_withdrawn', ['id' => 'dp_9', 'charge' => 'ch_early'])->assertOk();
        $this->webhook('evt_91', 'checkout.session.completed', ['id' => 'cs_9', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'payment_intent' => 'pi_early', 'amount_total' => 2000, 'currency' => 'usd',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'kind' => 'credits', 'pack' => 'credits_10']])->assertOk();

        $this->assertSame(0, $workspace->creditBalance());
    }

    public function test_signature_check_rejects_old_timestamps(): void
    {
        $payload = '{"id":"evt"}';
        $this->assertTrue(WebhookSignature::valid($payload, WebhookSignature::sign($payload, 'k'), 'k', 300));
        $this->assertFalse(WebhookSignature::valid($payload, WebhookSignature::sign($payload, 'k', time() - 3600), 'k', 300));
        $this->assertFalse(WebhookSignature::valid($payload, WebhookSignature::sign($payload, 'other'), 'k', 300));
    }

    private function webhook(string $id, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => time(), 'data' => ['object' => $object]]);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($payload, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** What Stripe currently reports for sub_1; tests change it to move the subscription along. */
    private array $stripeSubscriptions = [];

    /** A workspace with a Stripe customer whose subscription Stripe reports with the given status. */
    private function subscribedWorkspace(string $status): array
    {
        [$owner, $workspace] = $this->workspaceUser();
        $workspace->subscription()->create(['stripe_customer_id' => 'cus_1']);
        $this->stripeSays('sub_1', $status, $workspace);
        Http::fake([
            'api.stripe.com/v1/subscriptions/*' => fn (Request $request) => Http::response($this->stripeSubscriptions[basename(parse_url($request->url(), PHP_URL_PATH))] ?? [], 200),
        ]);
        $this->webhook('evt_seed_'.$workspace->id, 'customer.subscription.created', ['id' => 'sub_1', 'customer' => 'cus_1'])->assertOk();

        return [$owner, $workspace->refresh()];
    }

    private function stripeSays(string $id, string $status, Workspace $workspace): void
    {
        $this->stripeSubscriptions[$id] = [
            'id' => $id, 'object' => 'subscription', 'customer' => 'cus_1', 'status' => $status,
            'cancel_at_period_end' => false, 'canceled_at' => $status === 'canceled' ? time() - 20 * 86400 : null,
            'ended_at' => $status === 'canceled' ? time() : null,
            'metadata' => ['workspace_id' => (string) $workspace->id],
            'items' => ['data' => [['price' => ['id' => 'price_1'], 'current_period_end' => time() + 30 * 86400]]],
        ];
    }

    private function workspaceUser(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Billing Agency '.$user->id]);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
