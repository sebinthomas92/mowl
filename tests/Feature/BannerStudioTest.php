<?php

namespace Tests\Feature;

use App\Jobs\GenerateBannerCreative;
use App\Livewire\CampaignWorkspace;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BannerBatchCreator;
use App\Services\BannerJobDispatcher;
use App\Services\BannerJobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class BannerStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('campaigns.banners.enabled', true);
        config()->set('campaigns.banners.generator', 'mock');
        config()->set('campaigns.banners.disk', 'local');
        config()->set('campaigns.processing_mode', 'request');
        Storage::fake('local');
    }

    public function test_the_included_batch_is_idempotent_exact_and_free(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $creator = app(BannerBatchCreator::class);

        $first = $creator->createIncluded($workspace, $pack, $user);
        $second = $creator->createIncluded($workspace, $pack, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('banner_generation_batches', 1);
        $this->assertDatabaseCount('banner_creatives', 3);
        $this->assertSame(5, $workspace->creditBalance());
        $this->assertSame(
            ['Approved hero headline', 'Approved problem headline', 'Approved lifestyle headline'],
            $first->creatives->pluck('headline')->all(),
        );
        $this->assertSame(3, $first->creatives->pluck('direction')->unique()->count());
        foreach ($first->creatives as $creative) {
            $this->assertStringContainsString('expert ad creative prompt engineer', $creative->prompt);
            $this->assertStringContainsString('PRODUCT DNA', $creative->prompt);
            $this->assertStringContainsString('first supplied product image', $creative->prompt);
            $this->assertStringContainsString('Do not include any text', $creative->prompt);
            $this->assertStringContainsString('logos', $creative->prompt);
            $this->assertStringContainsString('watermarks', $creative->prompt);
        }
    }

    public function test_request_mode_generates_private_1080_by_1350_pngs_and_downloads_are_tenant_isolated(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);

        foreach ($batch->creatives as $creative) {
            app(BannerJobRunner::class)->run($creative);
        }

        $batch->refresh();
        $creative = $batch->creatives()->firstOrFail();
        $this->assertSame('completed', $batch->status);
        $this->assertSame('completed', $creative->status);
        Storage::disk('local')->assertExists($creative->output_path);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($creative->output_path));
        $this->assertSame([1080, 1350], [$dimensions[0], $dimensions[1]]);
        $this->actingAs($user)->get(route('campaign-banners.download', [$pack, $creative]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('campaign-banners.image', [$pack, $creative]))->assertNotFound();
    }

    public function test_an_additional_banner_debits_one_credit_and_terminal_failure_refunds_exactly_once(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $creator = app(BannerBatchCreator::class);
        $included = $creator->createIncluded($workspace, $pack, $user);
        $included->creatives()->update(['status' => 'completed']);
        $included->update(['status' => 'completed', 'completed_at' => now()]);

        $additional = $creator->createAdditional($workspace, $pack, $user);
        $this->assertSame(4, $workspace->creditBalance());
        $this->assertDatabaseHas('workspace_credits', [
            'banner_generation_batch_id' => $additional->id,
            'event' => 'banner_generation',
            'amount' => -1,
        ]);

        config()->set('campaigns.banners.generator', 'unavailable');
        $creative = $additional->creatives()->firstOrFail();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                app(BannerJobRunner::class)->run($creative->fresh());
            } catch (\InvalidArgumentException) {
            }
        }
        (new GenerateBannerCreative($creative->id))->failed(new RuntimeException('Provider unavailable'));

        $this->assertSame(5, $workspace->creditBalance());
        $this->assertSame(3, $creative->fresh()->attempts);
        $this->assertSame('failed', $creative->fresh()->status);
        $this->assertSame(1, $workspace->credits()->where('banner_generation_batch_id', $additional->id)->where('event', 'banner_generation_refund')->count());
    }

    public function test_included_failures_retry_only_missing_slots_without_a_credit_charge(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $creator = app(BannerBatchCreator::class);
        $batch = $creator->createIncluded($workspace, $pack, $user);
        $creatives = $batch->creatives()->orderBy('id')->get();
        $creatives[0]->update(['status' => 'completed', 'attempts' => 1]);
        $creatives[1]->update(['status' => 'failed', 'attempts' => 3, 'error_message' => 'Failed']);
        $creatives[2]->update(['status' => 'completed', 'attempts' => 1]);
        $batch->update(['status' => 'partial']);

        $retried = $creator->retryIncluded($workspace, $pack);

        $this->assertSame(['completed', 'queued', 'completed'], $retried->creatives->sortBy('id')->pluck('status')->all());
        $this->assertSame([1, 0, 1], $retried->creatives->sortBy('id')->pluck('attempts')->all());
        $this->assertSame(5, $workspace->creditBalance());
    }

    public function test_only_the_current_approved_version_can_generate_and_the_entitlement_does_not_reset(): void
    {
        [$user, $workspace, $pack] = $this->fixture(status: 'draft', reviewStatus: 'draft');
        $creator = app(BannerBatchCreator::class);

        try {
            $creator->createIncluded($workspace, $pack, $user);
            $this->fail('A draft pack generated banners.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('banner', $exception->errors());
        }

        $version = $pack->versions()->firstOrFail();
        $version->update(['review_status' => 'approved']);
        $pack->update(['status' => 'approved']);
        $included = $creator->createIncluded($workspace, $pack->fresh(), $user);
        $included->creatives()->update(['status' => 'completed']);
        $included->update(['status' => 'completed']);
        $secondVersion = $pack->versions()->create([
            'version' => 2,
            'generator' => 'mock',
            'review_status' => 'approved',
            'content' => $this->content(['V2 headline']),
            'evidence' => [],
            'compliance_flags' => [],
        ]);
        $pack->update(['current_version' => 2, 'status' => 'approved']);

        $this->assertSame($included->id, $creator->createIncluded($workspace, $pack->fresh(), $user)->id);
        $additional = $creator->createAdditional($workspace, $pack->fresh(), $user);
        $this->assertSame($secondVersion->id, $additional->campaign_pack_version_id);
    }

    public function test_branding_is_saved_and_the_studio_uses_the_automatic_product_reference(): void
    {
        [$user, $workspace, $pack] = $this->fixture();

        Livewire::actingAs($user)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->assertSee('Captured from product page')
            ->assertDontSee('Upload images')
            ->assertDontSee('Product images');

        $this->actingAs($user);
        session(['current_workspace_id' => $workspace->id]);
        $component = app(CampaignWorkspace::class);
        $component->packId = $pack->id;
        $component->bannerPrimaryColor = '#123456';
        $component->bannerLogo = UploadedFile::fake()->image('logo.png', 180, 60);
        $component->saveBannerBranding();

        $brand = $pack->product->brand->fresh();
        $this->assertSame('#123456', $brand->primary_color);
        $this->assertNotNull($brand->banner_logo_path);
        $this->assertSame(1, $pack->product->mediaAssets()->where('type', 'image')->count());
        Storage::disk('local')->assertExists($brand->banner_logo_path);
    }

    public function test_a_missing_website_image_is_explained_without_offering_an_upload(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $pack->product->mediaAssets()->delete();
        $path = "campaign-media/{$workspace->id}/{$pack->product_id}/manual.png";
        Storage::disk('local')->put($path, $this->imageBytes());
        $pack->product->mediaAssets()->create([
            'workspace_id' => $workspace->id,
            'source_snapshot_id' => $pack->source_snapshot_id,
            'type' => 'image',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'manual.png',
            'mime_type' => 'image/png',
            'size_bytes' => Storage::disk('local')->size($path),
            'content_hash' => hash('sha256', Storage::disk('local')->get($path)),
            'status' => 'ready',
            'metadata' => ['origin' => 'manual_upload'],
        ]);

        Livewire::actingAs($user)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->assertSee('No usable source image found')
            ->assertSee('product page did not expose a usable')
            ->assertDontSee('Upload images');

        try {
            app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);
            $this->fail('A banner batch was created without an automatically captured product image.');
        } catch (ValidationException $exception) {
            $this->assertSame('No usable product image was found on the product page.', $exception->errors()['banner'][0]);
        }
    }

    public function test_only_one_banner_request_can_be_active(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $creator = app(BannerBatchCreator::class);
        $included = $creator->createIncluded($workspace, $pack, $user);
        $included->creatives()->update(['status' => 'completed']);
        $included->update(['status' => 'completed']);
        $creator->createAdditional($workspace, $pack, $user);

        $this->expectException(ValidationException::class);
        $creator->createAdditional($workspace, $pack, $user);
    }

    public function test_an_additional_banner_is_blocked_when_the_credit_balance_is_insufficient(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $creator = app(BannerBatchCreator::class);
        $included = $creator->createIncluded($workspace, $pack, $user);
        $included->creatives()->update(['status' => 'completed']);
        $included->update(['status' => 'completed']);
        $workspace->credits()->create(['amount' => -5, 'event' => 'test_spend', 'description' => 'Spent test credits']);

        $this->expectException(ValidationException::class);
        $creator->createAdditional($workspace, $pack, $user);
    }

    public function test_queue_mode_dispatches_one_unique_job_per_creative(): void
    {
        Queue::fake();
        config()->set('campaigns.processing_mode', 'queue');
        [$user, $workspace, $pack] = $this->fixture();
        $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);

        app(BannerJobDispatcher::class)->dispatch($batch);

        Queue::assertPushed(GenerateBannerCreative::class, 3);
    }

    public function test_the_signed_request_endpoint_processes_one_creative_at_a_time(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);
        $creative = $batch->creatives()->oldest()->firstOrFail();
        $url = URL::temporarySignedRoute('banner-creatives.process', now()->addMinutes(5), ['bannerCreative' => $creative]);

        $this->actingAs($user)->post($url)->assertOk()->assertJson(['status' => 'completed']);

        $this->assertSame(1, $batch->creatives()->where('status', 'completed')->count());
        $this->assertSame(2, $batch->creatives()->where('status', 'queued')->count());
    }

    public function test_banner_costs_and_credit_events_appear_in_usage_reporting(): void
    {
        [$user, $workspace, $pack] = $this->fixture();
        $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);
        $batch->update(['status' => 'completed', 'provider' => 'google', 'estimated_cost' => 0.201]);

        $this->actingAs($user)->get(route('usage.index'))
            ->assertOk()
            ->assertSee('Banner studio')
            ->assertSee('$0.201');
    }

    private function fixture(string $status = 'approved', string $reviewStatus = 'approved'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Banner Agency']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $workspace->credits()->create(['amount' => 5, 'event' => 'test_allocation', 'description' => 'Test credits']);
        $brand = $workspace->brands()->create(['name' => 'Exact Brand']);
        $product = $brand->products()->create(['name' => 'Source Product']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/product',
            'content_hash' => hash('sha256', 'source'),
            'status' => 'ready',
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Banner Pack',
            'status' => $status,
            'current_version' => 1,
            'analysis_mode' => 'standard',
        ]);
        $pack->versions()->create([
            'version' => 1,
            'generator' => 'mock',
            'review_status' => $reviewStatus,
            'content' => $this->content(),
            'evidence' => [],
            'compliance_flags' => [],
        ]);
        $path = "campaign-media/{$workspace->id}/{$product->id}/product.png";
        Storage::disk('local')->put($path, $this->imageBytes());
        $product->mediaAssets()->create([
            'workspace_id' => $workspace->id,
            'source_snapshot_id' => $source->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'product.png',
            'mime_type' => 'image/png',
            'size_bytes' => Storage::disk('local')->size($path),
            'content_hash' => hash('sha256', Storage::disk('local')->get($path)),
            'status' => 'ready',
            'metadata' => [
                'origin' => 'product_page',
                'source_url' => 'https://example.com/images/product.png',
                'candidate_rank' => 1,
                'width' => 600,
                'height' => 800,
                'auto_imported' => true,
            ],
        ]);

        return [$user, $workspace, $pack->fresh(['product.brand', 'versions'])];
    }

    private function content(array $headlines = ['Approved hero headline', 'Approved problem headline', 'Approved lifestyle headline']): array
    {
        return [
            'direction' => ['title' => 'Approved direction', 'summary' => 'Approved summary'],
            'product_truth' => ['name' => 'Source Product', 'price' => '', 'source' => 'https://example.com/product', 'verified_facts' => []],
            'audiences' => ['Approved audience'],
            'benefits' => ['Approved benefit'],
            'meta' => ['primary_text' => 'Approved primary', 'headlines' => $headlines, 'descriptions' => ['Approved description']],
            'hooks' => ['Approved hook'],
            'script' => [],
            'captions' => [],
            'shot_log' => [],
        ];
    }

    private function imageBytes(): string
    {
        $image = imagecreatetruecolor(600, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 218, 173, 115));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
