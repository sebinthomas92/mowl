<?php

namespace Tests\Unit;

use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductHubContentAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubContentAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_campaign_content_maps_to_useful_hub_channels(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Legacy']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Brand'])->products()->create(['name' => 'Legacy Product', 'summary' => 'Legacy detail']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/legacy', 'content_hash' => hash('sha256', 'legacy'), 'status' => 'ready',
            'extracted_content' => 'Legacy detail', 'extracted_truth' => ['description' => 'Legacy detail'],
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Legacy pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $version = $pack->versions()->create([
            'version' => 1, 'generator' => 'legacy', 'review_status' => 'approved',
            'content' => ['direction' => ['title' => 'Useful direction', 'summary' => 'A clearer choice'], 'hooks' => ['Useful hook'], 'benefits' => ['Legacy benefit'], 'audiences' => ['Legacy buyers'], 'meta' => ['primary_text' => 'Legacy primary copy', 'headlines' => ['Legacy headline'], 'descriptions' => ['Legacy description']], 'script' => [], 'captions' => [], 'shot_log' => []],
            'evidence' => [], 'compliance_flags' => [],
        ]);

        $hub = app(ProductHubContentAdapter::class)->adapt($product, $version);

        $this->assertSame('Legacy primary copy', $hub['channels']['meta_ads']['primary_texts'][0]);
        $this->assertSame('Legacy headline', $hub['channels']['google_ads']['search']['headlines'][0]);
        $this->assertSame('https://example.com/legacy', $hub['channels']['google_ads']['search']['final_url']);
        $this->assertNotEmpty($hub['channels']['organic_social']['hooks']);
    }
}
