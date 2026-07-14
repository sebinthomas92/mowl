<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
            ->set('productUrl', 'https://93.184.216.34/products/kindle-stand')
            ->call('loadProductFromUrl')
            ->call('saveProduct')
            ->assertSet('step', 3)
            ->assertSet('sourceUrl', 'https://93.184.216.34/products/kindle-stand')
            ->call('generatePack')
            ->assertRedirect(route('campaign-packs.show', CampaignPack::firstOrFail()));

        $this->actingAs($user)
            ->get(route('campaign-packs.show', CampaignPack::firstOrFail()))
            ->assertOk()
            ->assertSee('Several routes explored. The strongest three lead.')
            ->assertSee('The everyday upgrade')
            ->assertSee('QA blocked');

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
        $this->assertNull(SourceSnapshot::firstOrFail()->refreshed_from_snapshot_id);
        $this->assertSame('ready', SourceSnapshot::firstOrFail()->status);
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

    public function test_product_details_are_loaded_from_a_product_page_before_saving(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Imported Brand']);
        Storage::fake('local');
        $image = $this->imageBytes();
        Http::fake([
            '93.184.216.34/media/canvas-tote.png' => Http::response($image, 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/*' => Http::response(<<<'HTML'
                <html><head><title>Fallback page title</title><meta property="og:image" content="/media/canvas-tote.png"><link rel="canonical" href="/products/canvas-tote"><script type="application/ld+json">{"@type":"Product","name":"Canvas Tote","description":"A roomy everyday carry tote.","image":"/media/second-choice.png","offers":{"price":"89","priceCurrency":"USD"}}</script></head><body>Product details</body></html>
                HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->set('brandId', $brand->id)
            ->set('step', 2)
            ->set('productUrl', 'https://93.184.216.34/store/canvas-tote')
            ->call('loadProductFromUrl')
            ->assertHasNoErrors()
            ->assertSet('productDetailsLoaded', true)
            ->assertSet('productName', 'Canvas Tote')
            ->assertSet('productPrice', 'USD 89')
            ->assertSet('productSummary', 'A roomy everyday carry tote.')
            ->assertSet('productUrl', 'https://93.184.216.34/products/canvas-tote')
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->assertSet('sourceUrl', 'https://93.184.216.34/products/canvas-tote');

        $this->assertDatabaseHas('products', [
            'brand_id' => $brand->id,
            'name' => 'Canvas Tote',
            'price' => 'USD 89',
            'summary' => 'A roomy everyday carry tote.',
        ]);
        $this->assertDatabaseHas('source_snapshots', [
            'product_id' => Product::firstOrFail()->id,
            'url' => 'https://93.184.216.34/products/canvas-tote',
            'status' => 'ready',
        ]);
        $asset = Product::firstOrFail()->mediaAssets()->firstOrFail();
        $this->assertSame('product_page', $asset->metadata['origin']);
        $this->assertSame('https://93.184.216.34/media/canvas-tote.png', $asset->metadata['source_url']);
        $this->assertSame(1, $asset->metadata['candidate_rank']);
        $this->assertSame(1, Product::firstOrFail()->mediaAssets()->count());
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_product_cannot_be_saved_without_analyzing_its_url(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Imported Brand']);

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->set('brandId', $brand->id)
            ->set('step', 2)
            ->set('productUrl', 'https://example.com/products/manual')
            ->set('productName', 'Manually entered product')
            ->call('saveProduct')
            ->assertHasErrors(['productUrl']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_campaign_setup_does_not_request_media_before_product_truth_is_complete(): void
    {
        [$user] = $this->workspaceUser();

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->assertDontSee('Product images or short videos')
            ->assertDontSee('Upload video');
    }

    public function test_existing_campaign_packs_render_while_banner_tables_are_pending(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Migration Safe Brand']);
        $product = $brand->products()->create(['name' => 'Migration Safe Product']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/migration-safe-product',
            'content_hash' => hash('sha256', 'migration-safe-product'),
            'status' => 'ready',
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Migration Safe Pack',
            'status' => 'draft',
            'current_version' => 1,
            'analysis_mode' => 'standard',
        ]);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source);
        $pack->versions()->create([
            'version' => 1,
            'generator' => 'mock',
            'content' => $result->content,
            'evidence' => $result->evidence,
            'compliance_flags' => $result->complianceFlags,
        ]);

        Schema::rename('banner_creatives', 'pending_banner_creatives');
        Schema::rename('banner_generation_batches', 'pending_banner_generation_batches');

        try {
            $this->actingAs($user)
                ->get(route('campaign-packs.show', $pack))
                ->assertOk()
                ->assertSee('Banner Studio is not enabled');
        } finally {
            Schema::rename('pending_banner_generation_batches', 'banner_generation_batches');
            Schema::rename('pending_banner_creatives', 'banner_creatives');
        }
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
            'section' => 'ranked_angles',
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
