<?php

namespace Tests\Feature;

use App\Data\GenerationResult;
use App\Livewire\CampaignWorkspace;
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
use Livewire\Livewire;
use Tests\TestCase;

class CampaignWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agency_can_generate_a_persisted_campaign_pack_from_a_brief(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $product = $workspace->brands()->create(['name' => 'Plush Republic'])->products()->create(['name' => 'Book-Shaped Kindle Stand', 'price' => 'USD 39']);
        Http::fake([
            '93.184.216.34/*' => Http::response('<html><head><title>Book-Shaped Kindle Stand</title><meta name="description" content="A stable wooden stand for e-readers."></head><body>Hands-free reading at a comfortable angle.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $pages = [];
        $this->app->instance(MockCampaignPackGenerator::class, new class($pages) extends MockCampaignPackGenerator
        {
            public function __construct(private array &$pages) {}

            public function generate(Product $product, SourceSnapshot $source, array $page = []): GenerationResult
            {
                $this->pages[] = $page;

                return parent::generate($product, $source, $page);
            }
        });

        Livewire::actingAs($user)->withQueryParams(['product' => $product->id])->test(CampaignWorkspace::class)
            ->assertSet('step', 3)
            ->assertSet('briefOffer', 'USD 39')
            ->set('sourceUrl', 'https://93.184.216.34/products/kindle-stand')
            ->set('objective', 'refresh')
            ->set('briefMessage', 'Reading should feel effortless.')
            ->call('generatePack')
            ->assertHasNoErrors()
            ->assertRedirect(route('campaign-packs.show', CampaignPack::firstOrFail()));

        $pack = CampaignPack::with('versions')->firstOrFail();
        $this->assertSame(['objective' => 'Refresh creative', 'offer' => 'USD 39', 'message' => 'Reading should feel effortless.'], $pack->brief);
        $this->assertSame($pack->brief, $pages[0]['campaign_brief'] ?? null);

        $this->actingAs($user)
            ->get(route('campaign-packs.show', $pack))
            ->assertOk()
            ->assertSee('each reviewed on its own')
            ->assertSee('Ranked angles')
            ->assertSee('aria-label="Workspace sections"', false)
            ->assertSee('aria-label="Review, ', false);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('source_snapshots', 1);
        $this->assertDatabaseCount('campaign_pack_versions', 1);
        $this->assertDatabaseCount('campaign_generation_jobs', 1);
        $this->assertSame('mock', $pack->versions->first()->generator);
        $this->assertSame('ready', SourceSnapshot::firstOrFail()->status);
        $this->assertSame(7, $pack->contentItems()->count());
        $this->assertSame(49, $workspace->creditBalance());
    }

    public function test_the_create_screen_lists_only_this_workspaces_products(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $mine = $workspace->brands()->create(['name' => 'Mine'])->products()->create(['name' => 'My Lamp']);
        $theirs = Workspace::create(['name' => 'Other'])->brands()->create(['name' => 'Theirs'])->products()->create(['name' => 'Their Lamp']);

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->assertSee('My Lamp')
            ->assertDontSee('Their Lamp')
            ->call('chooseProduct', $mine->id)
            ->assertSet('productId', $mine->id)
            ->assertSee('Start run · 1 credit');

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->call('chooseProduct', $theirs->id)
            ->assertStatus(404);
    }

    public function test_deep_analysis_is_blocked_when_credits_run_short(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $workspace->credits()->create(['amount' => -48, 'event' => 'pack_generation', 'description' => 'Earlier runs']);
        $product = $workspace->brands()->create(['name' => 'Short'])->products()->create(['name' => 'Short Lamp']);

        Livewire::actingAs($user)->withQueryParams(['product' => $product->id])->test(CampaignWorkspace::class)
            ->set('analysisMode', 'deep')
            ->assertSee('This run needs 3 credits and 2 are left.')
            ->set('sourceUrl', 'https://example.com/products/short-lamp')
            ->call('generatePack')
            ->assertHasErrors(['sourceUrl']);

        $this->assertDatabaseCount('campaign_packs', 0);
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

    public function test_the_brief_validates_required_input(): void
    {
        [$user, $workspace] = $this->workspaceUser();

        $product = $workspace->brands()->create(['name' => 'Valid'])->products()->create(['name' => 'Valid Lamp']);

        Livewire::actingAs($user)->withQueryParams(['product' => $product->id])->test(CampaignWorkspace::class)
            ->set('sourceUrl', '')
            ->set('objective', 'nonsense')
            ->call('generatePack')
            ->assertHasErrors(['sourceUrl' => 'required', 'objective']);
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
}
