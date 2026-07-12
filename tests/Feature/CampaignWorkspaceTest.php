<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agency_can_generate_a_persisted_campaign_pack(): void
    {
        [$user] = $this->workspaceUser();
        Http::fake([
            '93.184.216.34/*' => Http::response('<html><head><title>Book-Shaped Kindle Stand</title><meta name="description" content="A stable wooden stand for e-readers."></head><body>Hands-free reading at a comfortable angle.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->set('brandName', 'Plush Republic')
            ->set('brandWebsite', 'https://example.com')
            ->call('saveBrand')
            ->assertSet('step', 2)
            ->set('productName', 'Book-Shaped Kindle Stand')
            ->set('productPrice', '₹899')
            ->call('saveProduct')
            ->assertSet('step', 3)
            ->set('sourceUrl', 'https://93.184.216.34/products/kindle-stand')
            ->call('generatePack')
            ->assertRedirect(route('campaign-packs.show', CampaignPack::firstOrFail()));

        $this->actingAs($user)
            ->get(route('campaign-packs.show', CampaignPack::firstOrFail()))
            ->assertOk()
            ->assertSee('Ready for Ads Manager')
            ->assertSee('A smarter everyday upgrade');

        $this->assertDatabaseCount('brands', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('source_snapshots', 1);
        $this->assertDatabaseCount('campaign_packs', 1);
        $this->assertDatabaseCount('campaign_pack_versions', 1);
        $this->assertDatabaseCount('campaign_generation_jobs', 1);
        $this->assertDatabaseCount('processing_cache_entries', 2);

        $pack = CampaignPack::with('versions')->firstOrFail();
        $this->assertSame('mock', $pack->versions->first()->generator);
        $this->assertSame(hash('sha256', '<html><head><title>Book-Shaped Kindle Stand</title><meta name="description" content="A stable wooden stand for e-readers."></head><body>Hands-free reading at a comfortable angle.</body></html>'), SourceSnapshot::firstOrFail()->content_hash);
        $this->assertSame('Plush Republic', Brand::firstOrFail()->name);
        $this->assertSame('Book-Shaped Kindle Stand', Product::firstOrFail()->name);
    }

    public function test_an_existing_workspace_brand_can_be_reused(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Existing Brand']);

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->set('brandId', $brand->id)
            ->call('useBrand')
            ->assertSet('step', 2);
    }

    public function test_media_metadata_is_persisted_after_the_temporary_upload_is_stored(): void
    {
        Storage::fake('local');
        Queue::fake();
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Media Brand']);
        $product = $brand->products()->create(['name' => 'Demo Product']);
        $upload = UploadedFile::fake()->create('demo.mp4', 512, 'video/mp4');

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->set('productId', $product->id)
            ->set('step', 3)
            ->set('sourceUrl', 'https://example.com/products/demo')
            ->set('mediaUploads', [$upload])
            ->call('generatePack')
            ->assertHasNoErrors();

        $asset = $product->mediaAssets()->firstOrFail();
        $this->assertSame('demo.mp4', $asset->original_name);
        $this->assertSame('video/mp4', $asset->mime_type);
        $this->assertSame(512 * 1024, $asset->size_bytes);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_media_upload_controls_can_be_disabled_until_production_storage_is_configured(): void
    {
        config(['campaigns.media.uploads_enabled' => false]);
        [$user] = $this->workspaceUser();

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->assertDontSee('Product images or short videos');
    }

    public function test_each_setup_step_validates_required_input(): void
    {
        [$user] = $this->workspaceUser();

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->call('saveBrand')
            ->assertHasErrors(['brandName' => 'required']);
    }

    public function test_request_processing_renders_a_signed_trigger_for_section_regeneration(): void
    {
        config(['campaigns.processing_mode' => 'request']);
        Queue::fake();
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Regeneration Brand']);
        $product = $brand->products()->create(['name' => 'Regeneration Product']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/product',
            'content_hash' => hash('sha256', 'source'),
            'status' => 'ready',
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Regeneration Pack',
            'status' => 'approved',
            'current_version' => 1,
            'analysis_mode' => 'standard',
        ]);
        $pack->versions()->create([
            'version' => 1,
            'content' => [
                'product_truth' => ['name' => 'Regeneration Product', 'price' => '', 'source' => 'https://example.com/product', 'verified_facts' => []],
                'direction' => ['title' => 'Direction', 'summary' => 'Summary'],
                'audiences' => [],
                'benefits' => [],
                'meta' => ['primary_text' => 'Primary text', 'headlines' => [], 'descriptions' => []],
                'hooks' => [],
                'script' => [],
                'captions' => [],
                'shot_log' => [],
            ],
            'evidence' => [],
            'compliance_flags' => [],
            'generator' => 'mock',
        ]);

        Livewire::actingAs($user)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('regenerateSection')
            ->assertSee('campaign-jobs');

        $this->assertDatabaseHas('campaign_generation_jobs', [
            'campaign_pack_id' => $pack->id,
            'section' => 'meta',
            'status' => 'queued',
        ]);
        Queue::assertNothingPushed();
    }

    private function workspaceUser(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency Workspace']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $workspace->credits()->create([
            'amount' => 50,
            'event' => 'beta_allocation',
            'description' => 'Initial beta pack credits',
        ]);

        return [$user, $workspace];
    }
}
