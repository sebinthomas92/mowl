<?php

namespace Tests\Feature;

use App\Jobs\GenerateCampaignPack;
use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\Workspace;
use App\Services\CampaignGeneratorManager;
use App\Services\MediaProcessor;
use App\Services\ProductPageFetcher;
use App\Services\ProviderCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CampaignGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_fetches_caches_generates_and_versions_a_pack(): void
    {
        [$workspace, $product, $source, $pack, $job] = $this->generationFixture();
        Http::fake(['93.184.216.34/*' => Http::response($this->productHtml(), 200, ['Content-Type' => 'text/html'])]);

        (new GenerateCampaignPack($job->id))->handle(
            app(ProductPageFetcher::class),
            app(MediaProcessor::class),
            app(CampaignGeneratorManager::class),
            app(ProviderCostCalculator::class),
        );

        $this->assertSame('approved', $pack->fresh()->status);
        $this->assertSame(1, $pack->fresh()->current_version);
        $this->assertSame('ready', $source->fresh()->status);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('0.018000', $job->fresh()->estimated_cost);
        $this->assertDatabaseCount('campaign_pack_versions', 1);
        $this->assertDatabaseCount('processing_cache_entries', 2);
        $this->assertDatabaseCount('campaign_job_events', 2);
        $this->assertDatabaseHas('campaign_job_events', ['campaign_generation_job_id' => $job->id, 'type' => 'completed']);
        $this->assertSame(49, $workspace->creditBalance());

        $secondSource = $product->sourceSnapshots()->create([
            'url' => 'https://93.184.216.34/products/tote',
            'content_hash' => hash('sha256', 'pending'),
            'status' => 'pending',
        ]);
        $secondPack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $secondSource->id,
            'name' => 'Second Pack',
            'status' => 'queued',
            'current_version' => 0,
        ]);
        $secondJob = CampaignGenerationJob::create([
            'workspace_id' => $workspace->id,
            'campaign_pack_id' => $secondPack->id,
            'source_snapshot_id' => $secondSource->id,
        ]);
        (new GenerateCampaignPack($secondJob->id))->handle(
            app(ProductPageFetcher::class),
            app(MediaProcessor::class),
            app(CampaignGeneratorManager::class),
            app(ProviderCostCalculator::class),
        );

        $this->assertTrue($secondJob->fresh()->cache_hit);
        $this->assertDatabaseCount('processing_cache_entries', 2);
    }

    public function test_a_terminal_failure_marks_the_pack_and_refunds_reserved_credits(): void
    {
        [$workspace, , , $pack, $job] = $this->generationFixture();
        $queued = new GenerateCampaignPack($job->id);

        $queued->failed(new RuntimeException('Source could not be reached.'));
        $queued->failed(new RuntimeException('Source could not be reached.'));

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('failed', $pack->fresh()->status);
        $this->assertSame(50, $workspace->creditBalance());
        $this->assertDatabaseHas('workspace_credits', [
            'campaign_pack_id' => $pack->id,
            'campaign_generation_job_id' => $job->id,
            'event' => 'generation_refund',
            'amount' => 1,
        ]);
        $this->assertDatabaseCount('workspace_credits', 3);
        $this->assertDatabaseCount('campaign_job_events', 1);
        $this->assertDatabaseHas('campaign_job_events', ['campaign_generation_job_id' => $job->id, 'type' => 'failed']);
    }

    public function test_each_failed_retry_is_refunded_once(): void
    {
        [$workspace, , , $pack, $initialJob] = $this->generationFixture();
        (new GenerateCampaignPack($initialJob->id))->failed(new RuntimeException('Initial failure.'));

        $retryJob = CampaignGenerationJob::create([
            'workspace_id' => $workspace->id,
            'campaign_pack_id' => $pack->id,
            'source_snapshot_id' => $pack->source_snapshot_id,
            'credit_cost' => 1,
        ]);
        $workspace->credits()->create([
            'campaign_pack_id' => $pack->id,
            'campaign_generation_job_id' => $retryJob->id,
            'amount' => -1,
            'event' => 'generation_retry',
            'description' => 'Retry',
        ]);

        $retry = new GenerateCampaignPack($retryJob->id);
        $retry->failed(new RuntimeException('Retry failure.'));
        $retry->failed(new RuntimeException('Retry failure.'));

        $this->assertSame(50, $workspace->creditBalance());
        $this->assertSame(
            2,
            $workspace->credits()->where('event', 'generation_refund')->count(),
        );
    }

    public function test_an_already_claimed_job_cannot_generate_a_duplicate_version(): void
    {
        [, , , , $job] = $this->generationFixture();
        $job->update(['status' => 'processing', 'attempts' => 1]);

        (new GenerateCampaignPack($job->id))->handle(
            app(ProductPageFetcher::class),
            app(MediaProcessor::class),
            app(CampaignGeneratorManager::class),
            app(ProviderCostCalculator::class),
        );

        $this->assertDatabaseCount('campaign_pack_versions', 0);
        $this->assertSame(1, $job->fresh()->attempts);
    }

    public function test_a_section_regeneration_creates_a_new_version_without_spending_an_included_credit(): void
    {
        [$workspace, , , $pack, $job] = $this->generationFixture();
        Http::fake(['93.184.216.34/*' => Http::response($this->productHtml(), 200, ['Content-Type' => 'text/html'])]);
        $initial = new GenerateCampaignPack($job->id);
        $initial->handle(app(ProductPageFetcher::class), app(MediaProcessor::class), app(CampaignGeneratorManager::class), app(ProviderCostCalculator::class));
        $originalTitle = $pack->fresh()->versions()->where('version', 1)->value('content')['direction']['title'];

        $regeneration = CampaignGenerationJob::create([
            'workspace_id' => $workspace->id,
            'campaign_pack_id' => $pack->id,
            'source_snapshot_id' => $pack->source_snapshot_id,
            'section' => 'direction',
            'base_version' => 1,
            'credit_cost' => 0,
        ]);
        (new GenerateCampaignPack($regeneration->id))->handle(
            app(ProductPageFetcher::class),
            app(MediaProcessor::class),
            app(CampaignGeneratorManager::class),
            app(ProviderCostCalculator::class),
        );

        $updated = $pack->fresh();
        $this->assertSame(2, $updated->current_version);
        $this->assertNotSame($originalTitle, $updated->versions()->where('version', 2)->value('content')['direction']['title']);
        $this->assertSame(49, $workspace->creditBalance());
        $this->assertSame('completed', $regeneration->fresh()->status);
    }

    private function generationFixture(): array
    {
        $workspace = Workspace::create(['name' => 'Agency']);
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
        $workspace->credits()->create([
            'campaign_pack_id' => $pack->id,
            'campaign_generation_job_id' => $job->id,
            'amount' => -1,
            'event' => 'pack_generation',
            'description' => 'Standard pack',
        ]);

        return [$workspace, $product, $source, $pack, $job];
    }

    private function productHtml(): string
    {
        return '<html><head><title>Canvas Tote</title><meta name="description" content="A roomy everyday tote."></head><body>Roomy and easy to carry.</body></html>';
    }
}
