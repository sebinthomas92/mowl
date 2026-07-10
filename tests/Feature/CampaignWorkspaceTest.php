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
use Livewire\Livewire;
use Tests\TestCase;

class CampaignWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agency_can_generate_a_persisted_campaign_pack(): void
    {
        [$user] = $this->workspaceUser();

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->set('brandName', 'Plush Republic')
            ->set('brandWebsite', 'https://example.com')
            ->call('saveBrand')
            ->assertSet('step', 2)
            ->set('productName', 'Book-Shaped Kindle Stand')
            ->set('productPrice', '₹899')
            ->call('saveProduct')
            ->assertSet('step', 3)
            ->set('sourceUrl', 'https://example.com/products/kindle-stand')
            ->call('generatePack')
            ->assertSet('step', 4)
            ->assertSee('Ready for Ads Manager')
            ->assertSee('A smarter everyday upgrade');

        $this->assertDatabaseCount('brands', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('source_snapshots', 1);
        $this->assertDatabaseCount('campaign_packs', 1);
        $this->assertDatabaseCount('campaign_pack_versions', 1);

        $pack = CampaignPack::with('versions')->firstOrFail();
        $this->assertSame('mock', $pack->versions->first()->generator);
        $this->assertSame(hash('sha256', 'https://example.com/products/kindle-stand'), SourceSnapshot::firstOrFail()->content_hash);
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

    public function test_each_setup_step_validates_required_input(): void
    {
        [$user] = $this->workspaceUser();

        Livewire::actingAs($user)->test(CampaignWorkspace::class)
            ->call('saveBrand')
            ->assertHasErrors(['brandName' => 'required']);
    }

    private function workspaceUser(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency Workspace']);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
