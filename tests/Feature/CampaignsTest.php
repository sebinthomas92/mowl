<?php

namespace Tests\Feature;

use App\Livewire\CampaignIndex;
use App\Livewire\ProductCampaigns;
use App\Models\Campaign;
use App\Models\CampaignPack;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CampaignActivity;
use App\Services\Campaigns\CampaignPlanner;
use App\Services\Campaigns\CampaignResults;
use App\Services\Campaigns\ResultsCsv;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Services\Pages\PageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_launches_only_when_ready_and_launch_pins_the_approved_versions(): void
    {
        Notification::fake();
        [$owner, $workspace, $pack] = $this->fixture();
        $teammate = User::factory()->create();
        $workspace->users()->attach($teammate, ['role' => 'member']);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();
        $offers = $pack->contentItems()->where('section', 'offers')->firstOrFail();
        $library->approve($positioning->latestVersion, $owner);

        $component = Livewire::actingAs($owner)->test(ProductCampaigns::class, ['productId' => $pack->product_id])
            ->assertSee('No campaigns yet')
            ->call('plan')
            ->assertSee('Edit plan');
        $campaign = Campaign::query()->sole();
        $this->assertSame('planned', $campaign->status);
        $this->assertSame([$positioning->id], $campaign->deliverables()->pluck('content_item_id')->all(), 'A plan starts with approved content only.');

        $component->set('form.items', [(string) $positioning->id, (string) $offers->id])
            ->set('form.starts_on', '2026-10-20')->set('form.ends_on', '2026-10-31')
            ->set('form.hypothesis', 'A hands-free angle converts better than a price-first message.')
            ->set('form.baseline', '1.4% page conversion (September)')
            ->call('save')->assertHasNoErrors()
            ->assertSee('20–31 Oct 2026')
            ->assertSee('No approved version yet');
        $this->assertSame(1.4, $campaign->fresh()->baseline_rate);

        $this->expectLaunchToFail($campaign, $owner, 'all deliverables approved');

        $library->approve($offers->latestVersion, $owner);
        $component->call('launch')->assertHasNoErrors()->assertSee('launched');
        $campaign->refresh();
        $this->assertSame('active', $campaign->status);
        $this->assertNotNull($campaign->launched_at);
        $this->assertSame([1, 1], $campaign->deliverables()->pluck('pinned_version')->all());
        Notification::assertSentTo($teammate, CampaignActivity::class, fn (CampaignActivity $notification) => $notification->event === 'launched');

        // A later revision does not change what the campaign ran with.
        $library->revise($positioning->fresh(), $owner);
        Livewire::actingAs($owner)->test(ProductCampaigns::class, ['productId' => $pack->product_id])
            ->assertSee('v2 exists; this campaign keeps v1');
        $this->assertSame(1, $campaign->deliverables()->where('content_item_id', $positioning->id)->value('pinned_version'));

        $this->expectException(ValidationException::class);
        app(CampaignPlanner::class)->update($campaign, ['name' => 'Changed after launch']);
    }

    public function test_a_campaign_with_an_unpublished_page_waits_for_the_page(): void
    {
        [$owner, , $pack] = $this->fixture();
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $library->approve($pack->contentItems()->where('section', 'positioning')->firstOrFail()->latestVersion, $owner);
        $page = app(PageBuilder::class)->start($pack->product, $owner);

        $campaign = app(CampaignPlanner::class)->plan($pack->product, $owner);
        $this->assertSame($page->id, $campaign->page_id);
        app(CampaignPlanner::class)->update($campaign, ['hypothesis' => 'H', 'baseline' => '2%', 'starts_on' => '2026-10-01']);

        $checks = collect(app(CampaignPlanner::class)->readiness($campaign->fresh()))->keyBy('key');
        $this->assertFalse($checks['page']['ok']);
        $this->assertTrue($checks['deliverables']['ok']);

        $page->update(['live_version' => $page->draft_version, 'publish_state' => 'live']);
        app(CampaignPlanner::class)->launch($campaign->fresh(), $owner);
        $this->assertSame($page->draft_version, $campaign->fresh()->pinned_page_version);
    }

    public function test_the_csv_reader_handles_analytics_and_store_exports(): void
    {
        $csv = app(ResultsCsv::class);

        $ga = $csv->parse("# ----------------------------------------\n# Landing page report\n# 20261001-20261003\nDate,Sessions,Ecommerce purchases,Purchase revenue\n20261001,212,4,\$216.00\n20261002,268,5,\"\$1,270.50\"\n20261003,301,6,\$330.00\nTotal,781,15,\"\$1,816.50\"\n");
        $this->assertSame(['2026-10-01', '2026-10-03'], [$ga['start'], $ga['end']]);
        $this->assertSame([781, 15, 1816.5, 'USD'], [$ga['visits'], $ga['orders'], $ga['revenue'], $ga['currency']]);

        $store = $csv->parse("Day;Orders;Net sales (EUR)\n13/10/2026;3;1.234,50\n14/10/2026;2;99,90\n13/10/2026;1;10\n");
        $this->assertSame('2026-10-13', $store['start'], 'Slashed dates are day-first when a day is above 12.');
        $this->assertNull($store['visits']);
        $this->assertSame([6, 1344.4], [$store['orders'], $store['revenue']]);
        $this->assertCount(2, $store['daily'], 'Rows for the same day add up.');

        foreach (["Week,Visits\n42,100\n" => 'Date', "Date,Bounce rate\n2026-10-01,40%\n" => 'No visits, orders or revenue', "Date,Visits\n2026-13-45,10\n" => "can't read"] as $bad => $expected) {
            try {
                $csv->parse($bad);
                $this->fail('Expected the CSV to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($expected, $exception->errors()['csv'][0]);
            }
        }
    }

    public function test_imported_results_compare_to_the_baseline_and_a_proposal_becomes_the_next_experiment(): void
    {
        [$owner, , $pack] = $this->fixture();
        $campaign = $this->launched($owner, $pack);

        $component = Livewire::actingAs($owner)->test(ProductCampaigns::class, ['productId' => $pack->product_id])
            ->assertSee('No results yet')
            ->call('openImport', $campaign->id)
            ->set('csv', "Date,Visits,Orders,Revenue\n2026-10-20,1000,10,540\n2026-10-21,1140,31,1674\n")
            ->set('csvLabel', 'shop-analytics.csv')
            ->call('importCsv')->assertHasNoErrors()
            ->assertSee('Imported 2 days from shop-analytics.csv')
            ->assertSee('1.92%')
            ->assertSee('vs 1.4% baseline')
            ->assertSee('Keep the creative, test the offer');

        $summary = app(CampaignResults::class)->summary($campaign->fresh());
        $this->assertSame([2140, 41, 2214.0, 1.92], [$summary['visits'], $summary['orders'], $summary['revenue'], $summary['conversion']]);

        $component->call('saveProposal', 0)->assertHasNoErrors()->assertSee('Saved “Keep the creative, test the offer” as a planned campaign.');
        $next = Campaign::query()->where('follows_campaign_id', $campaign->id)->sole();
        $this->assertSame('planned', $next->status);
        $this->assertSame(1.92, $next->baseline_rate);
        $this->assertSame($campaign->deliverables()->pluck('content_item_id')->all(), $next->deliverables()->pluck('content_item_id')->all());
        $this->assertNull($next->deliverables()->first()->pinned_version);

        $component->call('saveProposal', 0);
        $this->assertSame(1, Campaign::query()->where('follows_campaign_id', $campaign->id)->count(), 'Saving the same proposal twice keeps one plan.');

        Livewire::actingAs($owner)->test(ProductCampaigns::class, ['productId' => $pack->product_id, 'mode' => 'results'])
            ->assertSee($campaign->name)
            ->assertSee('AI generation usage · kept separate')
            ->assertSee('1.92%');
    }

    public function test_store_orders_are_pulled_from_woocommerce_and_combine_with_imported_visits(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $campaign = $this->launched($owner, $pack, ['starts_on' => now()->subDays(2)->format('Y-m-d')]);
        StoreConnection::create([
            'workspace_id' => $workspace->id, 'name' => 'Northline store', 'driver' => 'woocommerce', 'site_url' => 'https://shop.test',
            'api_base' => 'https://shop.test/wp-json/', 'username' => 'manager', 'secret' => 'abcd abcd', 'has_woocommerce' => true,
        ]);
        $today = now()->format('Y-m-d');
        Http::fake(function (Request $request) use ($today) {
            $url = $request->url();
            if (str_contains($url, '/wc/v3/products') && str_contains($url, 'slug=campaigns')) {
                return Http::response([['id' => 77, 'name' => 'Campaigns Product']]);
            }
            if (str_contains($url, '/wc/v3/orders')) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame('77', $query['product']);
                $this->assertSame('processing,completed', $query['status']);

                return Http::response([
                    ['id' => 1, 'currency' => 'inr', 'date_created' => $today.'T09:00:00', 'line_items' => [['product_id' => 77, 'total' => '540.00'], ['product_id' => 5, 'total' => '99.00']]],
                    ['id' => 2, 'currency' => 'inr', 'date_created' => $today.'T11:00:00', 'line_items' => [['product_id' => 77, 'total' => '270.00']]],
                ], 200, ['X-WP-TotalPages' => '1']);
            }

            return Http::response([], 404);
        });

        Livewire::actingAs($owner)->test(ProductCampaigns::class, ['productId' => $pack->product_id])
            ->call('pullOrders', $campaign->id)->assertHasNoErrors()
            ->assertSee('Pulled 2 orders from Northline store · Campaigns Product')
            ->assertSee('₹810')
            ->assertSee('Needs visits and orders');

        $result = $campaign->results()->sole();
        $this->assertSame([3, 2, 810.0, 'INR'], [count($result->daily), $result->orders, $result->revenue, $result->currency]);

        app(CampaignResults::class)->importCsv($campaign, "Date,Sessions\n{$today},100\n", 'ga.csv', $owner);
        $summary = app(CampaignResults::class)->summary($campaign->fresh());
        $this->assertSame([100, 2, 2.0, true], [$summary['visits'], $summary['orders'], $summary['conversion'], $summary['mixed']]);
    }

    public function test_imports_keep_to_the_campaign_dates_and_conversion_uses_shared_days(): void
    {
        [$owner, , $pack] = $this->fixture();
        $campaign = $this->launched($owner, $pack, ['ends_on' => '2026-10-22']);
        $results = app(CampaignResults::class);

        $orders = $results->importCsv($campaign, "Date,Orders,Revenue (EUR)\n2026-10-18,9,900\n2026-10-20,2,200\n2026-10-21,3,300\n2026-10-25,9,900\n", 'store.csv', $owner);
        $this->assertSame(['2026-10-20', '2026-10-21', 5, 500.0, 'EUR'], [$orders->window_start->format('Y-m-d'), $orders->window_end->format('Y-m-d'), $orders->orders, $orders->revenue, $orders->currency]);

        $results->importCsv($campaign, "Date,Sessions\n2026-10-21,100\n2026-10-22,400\n", 'ga.csv', $owner);
        $summary = $results->summary($campaign->fresh());
        $this->assertSame(3.0, $summary['conversion'], 'Only 21 Oct is in both imports: 3 orders from 100 visits.');

        try {
            $results->importCsv($campaign, "Date,Visits\n2026-09-01,10\n", 'old.csv', $owner);
            $this->fail('Rows outside the campaign dates should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('None of these days fall inside', $exception->errors()['csv'][0]);
        }
    }

    public function test_a_brand_scoped_member_only_sees_and_hears_about_their_brand(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $ours = $this->launched($owner, $pack, ['name' => 'Kettle autumn push']);
        $other = $workspace->brands()->create(['name' => 'Other Brand'])->products()->create(['name' => 'Other Product', 'summary' => 'Another product.']);
        $theirs = app(CampaignPlanner::class)->plan($other, $owner, null, ['name' => 'Other brand secret plan']);

        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member', 'brand_id' => $pack->product->brand_id]);

        Livewire::actingAs($member)->test(CampaignIndex::class)
            ->assertSee('Kettle autumn push')
            ->assertDontSee('Other brand secret plan');
        Livewire::actingAs($member)->test(ProductCampaigns::class, ['productId' => $other->id])->assertStatus(404);
        Livewire::actingAs($member)->test(ProductCampaigns::class, ['productId' => $pack->product_id])->assertSee('Kettle autumn push');

        Notification::fake();
        $planner = app(CampaignPlanner::class);
        $planner->notify($theirs, 'launched', $owner);
        Notification::assertNotSentTo($member, CampaignActivity::class);
        $planner->notify($ours, 'launched', $owner);
        Notification::assertSentTo($member, CampaignActivity::class);
    }

    public function test_campaigns_stay_inside_their_workspace(): void
    {
        [$owner, , $pack] = $this->fixture();
        $campaign = $this->launched($owner, $pack);
        [$stranger, , $otherPack] = $this->fixture('Other');
        app(CampaignPlanner::class)->plan($otherPack->product, $stranger);

        Livewire::actingAs($owner)->test(CampaignIndex::class)
            ->assertSee($campaign->name)
            ->assertDontSee('Other Product campaign');

        $this->actingAs($stranger)->get(route('products.show', [$pack->product_id, 'tab' => 'campaigns']))->assertNotFound();
        Livewire::actingAs($stranger)->test(ProductCampaigns::class, ['productId' => $otherPack->product_id])
            ->call('show', $campaign->id)
            ->assertNotFound();
    }

    public function test_the_campaigns_pages_render_in_the_shell(): void
    {
        [$owner, , $pack] = $this->fixture();
        $this->launched($owner, $pack);

        $this->actingAs($owner)->get(route('campaigns.index'))->assertOk()->assertSee('Campaigns live inside each product')->assertSee('Campaigns Product campaign');
        $this->actingAs($owner)->get(route('products.show', [$pack->product_id, 'tab' => 'campaigns']))->assertOk()->assertSee('Deliverables · exact versions');
        $this->actingAs($owner)->get(route('products.show', [$pack->product_id, 'tab' => 'results']))->assertOk()->assertSee('AI generation usage');
        $this->actingAs($owner)->get(route('products.show', $pack->product_id))->assertOk()->assertSee('Campaigns and results');
    }

    private function expectLaunchToFail(Campaign $campaign, User $owner, string $reason): void
    {
        try {
            app(CampaignPlanner::class)->launch($campaign->fresh(), $owner);
            $this->fail('The launch should have been blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($reason, $exception->errors()['launch'][0]);
        }
        $this->assertSame('planned', $campaign->fresh()->status);
    }

    private function launched(User $owner, CampaignPack $pack, array $plan = []): Campaign
    {
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $library->approve($pack->contentItems()->where('section', 'positioning')->firstOrFail()->latestVersion, $owner);
        $planner = app(CampaignPlanner::class);
        $campaign = $planner->plan($pack->product, $owner);
        $planner->update($campaign, $plan + ['hypothesis' => 'Hands-free wins.', 'baseline' => '1.4% page conversion', 'starts_on' => '2026-10-20', 'audience' => 'Desk readers']);
        $planner->launch($campaign->fresh(), $owner);

        return $campaign->fresh();
    }

    private function fixture(string $name = 'Campaigns'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => "{$name} Agency"]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => "{$name} Brand"])->products()->create(['name' => "{$name} Product", 'summary' => 'A directly supported product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/product/'.strtolower($name).'/',
            'content_hash' => hash('sha256', $name),
            'status' => 'ready',
            'extracted_content' => 'A directly supported product fact.',
            'extracted_truth' => ['description' => 'A directly supported product fact.'],
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => "{$name} Pack", 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $workspace, $pack];
    }
}
