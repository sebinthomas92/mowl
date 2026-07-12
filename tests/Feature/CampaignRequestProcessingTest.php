<?php

namespace Tests\Feature;

use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CampaignJobDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CampaignRequestProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_processing_leaves_the_job_for_a_signed_request(): void
    {
        config(['campaigns.processing_mode' => 'request']);
        Queue::fake();

        app(CampaignJobDispatcher::class)->dispatch(123);

        Queue::assertNothingPushed();
    }

    public function test_a_workspace_member_can_process_a_job_through_a_signed_route(): void
    {
        [$user, , $job] = $this->generationFixture();
        Http::fake(['93.184.216.34/*' => Http::response($this->productHtml(), 200, ['Content-Type' => 'text/html'])]);
        $url = URL::temporarySignedRoute('campaign-jobs.process', now()->addMinute(), ['generationJob' => $job]);

        $this->actingAs($user)->post($url)
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertDatabaseCount('campaign_pack_versions', 1);
    }

    public function test_a_signed_job_route_is_still_scoped_to_the_users_workspace(): void
    {
        [, , $job] = $this->generationFixture();
        $outsider = User::factory()->create();
        $url = URL::temporarySignedRoute('campaign-jobs.process', now()->addMinute(), ['generationJob' => $job]);

        $this->actingAs($outsider)->post($url)->assertNotFound();
    }

    public function test_recovery_requires_the_cron_secret_and_processes_one_job(): void
    {
        [, , $job] = $this->generationFixture();
        config(['campaigns.cron_secret' => 'test-secret']);
        Http::fake(['93.184.216.34/*' => Http::response($this->productHtml(), 200, ['Content-Type' => 'text/html'])]);

        $this->get(route('campaign-jobs.recover'))->assertUnauthorized();
        $this->withToken('test-secret')->get(route('campaign-jobs.recover'))
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_recovery_does_not_reclaim_a_job_with_a_fresh_heartbeat(): void
    {
        [, , $job] = $this->generationFixture();
        config(['campaigns.cron_secret' => 'test-secret']);
        $job->update(['status' => 'processing', 'phase' => 'generating_pack', 'heartbeat_at' => now()]);

        $this->withToken('test-secret')->get(route('campaign-jobs.recover'))
            ->assertOk()
            ->assertJson(['status' => 'idle']);

        $this->assertSame('processing', $job->fresh()->status);
    }

    private function generationFixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $workspace->credits()->create(['amount' => 50, 'event' => 'beta_allocation', 'description' => 'Beta credits']);
        $brand = $workspace->brands()->create(['name' => 'Harbor']);
        $product = $brand->products()->create(['name' => 'Canvas Tote', 'price' => '$89']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://93.184.216.34/products/tote',
            'content_hash' => hash('sha256', 'pending'),
            'status' => 'pending',
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Canvas Tote Pack',
            'status' => 'queued',
            'current_version' => 0,
        ]);
        $job = CampaignGenerationJob::create([
            'workspace_id' => $workspace->id,
            'campaign_pack_id' => $pack->id,
            'source_snapshot_id' => $source->id,
        ]);

        return [$user, $workspace, $job];
    }

    private function productHtml(): string
    {
        return '<html><head><title>Canvas Tote</title><meta name="description" content="A roomy everyday tote."></head><body>Roomy and easy to carry.</body></html>';
    }
}
