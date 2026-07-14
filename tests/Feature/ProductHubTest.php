<?php

namespace Tests\Feature;

use App\Livewire\ProductHub;
use App\Models\CampaignPack;
use App\Models\ProductHubShare;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_library_opens_the_product_hub_and_tenants_are_isolated(): void
    {
        [$owner, , $product] = $this->fixture();
        $product->campaignPacks()->firstOrFail()->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);
        $outsider = User::factory()->create();
        $other = Workspace::create(['name' => 'Other']);
        $other->users()->attach($outsider, ['role' => 'owner']);

        $this->actingAs($owner)->get(route('products.index'))->assertOk()->assertSee(route('products.show', $product), false);
        $this->actingAs($owner)->get(route('products.show', $product))->assertOk()->assertSee('Google Ads copy');
        $this->actingAs($outsider)->get(route('products.show', $product))->assertNotFound();
    }

    public function test_hub_uses_the_latest_approved_version_and_excludes_drafts(): void
    {
        [$owner, , $product, $pack] = $this->fixture();
        $approved = $pack->versions()->firstOrFail();
        $approved->update(['review_status' => 'approved', 'reviewed_at' => now()->subDay()]);
        $draftContent = $approved->content;
        data_set($draftContent, 'marketing_hub.channels.google_ads.search.headlines.0', 'DRAFT SECRET COPY');
        $pack->versions()->create([
            'version' => 2, 'generator' => 'mock', 'review_status' => 'draft', 'content' => $draftContent,
            'evidence' => $approved->evidence, 'compliance_flags' => [],
        ]);

        $this->actingAs($owner)->get(route('products.show', $product))
            ->assertOk()->assertSee('Approved v1')->assertDontSee('DRAFT SECRET COPY');
    }

    public function test_owner_manages_validated_resources_and_revocable_share_links(): void
    {
        [$owner, , $product, $pack] = $this->fixture();
        $pack->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);

        Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])
            ->set('resourceInputs.google_ads_drive', 'not-a-url')->call('saveResources')
            ->assertHasErrors(['resourceInputs.google_ads_drive'])
            ->set('resourceInputs.google_ads_drive', 'https://drive.google.com/drive/folders/approved')
            ->call('saveResources')->assertHasNoErrors()
            ->call('createShare')->assertSet('shareUrl', fn ($url) => str_contains($url, '/shared/products/'));

        $share = $product->hubShares()->firstOrFail();
        $this->get(route('product-hubs.share', $share->token))->assertOk()->assertSee('Read-only client view');

        Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])->call('revokeShare');
        $this->get(route('product-hubs.share', $share->token))->assertNotFound();
    }

    public function test_agency_member_can_manage_product_hub_links_and_sharing(): void
    {
        [, $workspace, $product, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $pack->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);

        Livewire::actingAs($member)->test(ProductHub::class, ['product' => $product])
            ->set('resourceInputs.brand_guide', 'https://drive.google.com/brand-guide')
            ->call('saveResources')->assertHasNoErrors()
            ->call('createShare')->assertSet('shareUrl', fn ($url) => str_contains($url, '/shared/products/'));

        $this->assertDatabaseHas('product_resource_links', ['product_id' => $product->id, 'kind' => 'brand_guide']);
        $this->assertDatabaseCount('product_hub_shares', 1);
    }

    public function test_expired_share_is_not_accessible(): void
    {
        [$owner, $workspace, $product, $pack] = $this->fixture();
        $pack->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);
        $share = $product->hubShares()->create([
            'workspace_id' => $workspace->id, 'created_by_user_id' => $owner->id,
            'token' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('product-hubs.share', $share->token))->assertNotFound();
    }

    public function test_google_ads_csv_is_complete_utf8_and_available_to_members_and_shares(): void
    {
        [$owner, $workspace, $product, $pack] = $this->fixture('Café Product');
        $version = $pack->versions()->firstOrFail();
        $content = $version->content;
        data_set($content, 'marketing_hub.channels.google_ads.search.headlines.0', "Café, ready\nfor the moment");
        $version->update(['content' => $content, 'review_status' => 'approved', 'reviewed_at' => now()]);
        $share = ProductHubShare::create([
            'workspace_id' => $workspace->id, 'product_id' => $product->id, 'created_by_user_id' => $owner->id,
            'token' => hash('sha256', 'shared-csv'),
        ]);

        $response = $this->actingAs($owner)->get(route('products.hub-export', [$product, 'search']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8')
            ->assertHeader('content-disposition', 'attachment; filename="cafe-product-google-ads-search.csv"');
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('campaign_type,field_group,position,value,character_count,final_url', $csv);
        $this->assertStringContainsString('"Café, ready', $csv);
        $this->assertStringContainsString('final_url', $csv);

        $this->get(route('product-hubs.shared-export', [$share->token, 'search']))->assertOk();
    }

    public function test_product_media_requires_tenant_or_active_share_authorization(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $product, $pack] = $this->fixture();
        $pack->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);
        Storage::disk('local')->put('products/photo.jpg', 'image-bytes');
        $asset = $product->mediaAssets()->create([
            'workspace_id' => $workspace->id, 'type' => 'product_image', 'disk' => 'local', 'path' => 'products/photo.jpg',
            'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 11,
            'content_hash' => hash('sha256', 'image-bytes'), 'status' => 'ready',
        ]);
        $share = $product->hubShares()->create([
            'workspace_id' => $workspace->id, 'created_by_user_id' => $owner->id, 'token' => hash('sha256', 'media-share'),
        ]);
        $version = $pack->versions()->firstOrFail();
        $batch = $workspace->bannerGenerationBatches()->create([
            'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id,
            'requested_by_user_id' => $owner->id, 'kind' => 'included', 'requested_count' => 1,
            'status' => 'completed',
        ]);
        Storage::disk('local')->put('creatives/approved.png', 'creative-bytes');
        $creative = $batch->creatives()->create([
            'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id, 'sequence' => 1,
            'direction' => 'Product first', 'layout' => 'square', 'headline' => 'Approved creative',
            'prompt' => 'Approved source-linked creative.', 'status' => 'completed', 'disk' => 'local',
            'output_path' => 'creatives/approved.png',
        ]);

        $this->actingAs($owner)->get(route('products.hub-media', [$product, $asset]))->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->actingAs($owner)->get(route('products.hub-creative', [$product, $creative]))->assertOk()->assertHeader('content-type', 'image/png');
        auth()->logout();
        $this->get(route('product-hubs.shared-media', [$share->token, $asset]))->assertOk();
        $this->get(route('product-hubs.shared-creative', [$share->token, $creative]))->assertOk();
    }

    public function test_empty_state_and_campaign_history_are_honest(): void
    {
        [$owner, , $product, $pack] = $this->fixture();
        $this->actingAs($owner)->get(route('products.show', $product))->assertSee('No approved marketing pack yet');
        $pack->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);
        $this->actingAs($owner)->get(route('products.show', $product))->assertSee($pack->name)->assertSee('Campaign history');
    }

    private function fixture(string $name = 'Everyday Upgrade'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Northstar Performance']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $brand = $workspace->brands()->create(['name' => 'Harbor & Pine']);
        $product = $brand->products()->create(['name' => $name, 'price' => '$49', 'summary' => 'A useful product grounded in its source page.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/products/everyday-upgrade', 'content_hash' => hash('sha256', $name),
            'status' => 'ready', 'extracted_content' => $product->summary, 'extracted_truth' => ['description' => $product->summary],
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Everyday Upgrade Launch',
            'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard',
        ]);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create([
            'version' => 1, 'generator' => 'mock', 'content' => $result->content,
            'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags,
        ]);

        return [$owner, $workspace, $product, $pack];
    }
}
