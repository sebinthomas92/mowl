<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Livewire\TeamIndex;
use App\Livewire\WorkspaceSettings;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Stores and agencies share one app; only agencies see client features. */
class WorkspaceKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_up_asks_what_the_workspace_is_for(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Asha Rao')->set('workspaceName', 'Plush Republic')
            ->set('email', 'asha@plush.test')->set('password', 'campaign123')->set('password_confirmation', 'campaign123')
            ->call('register')->assertHasErrors(['kind' => 'required'])
            ->set('kind', 'store')->call('register')->assertHasNoErrors()->assertRedirect(route('products.index'));

        $this->assertSame('store', Workspace::query()->where('name', 'Plush Republic')->value('kind'));
    }

    public function test_a_store_sees_no_client_features_even_with_several_brands(): void
    {
        [$owner, $workspace, $product, $pack] = $this->fixture();
        $workspace->update(['kind' => 'store']);
        $workspace->brands()->create(['name' => 'Second Brand']);
        $pack->versions()->firstOrFail()->update(['review_status' => 'approved', 'reviewed_at' => now()]);

        $this->actingAs($owner)->get(route('products.show', [$product, 'tab' => 'hub']))->assertOk()
            ->assertSee('Share this work')->assertSee('Create share link')->assertSee('Ready to use · what people with the link see')
            ->assertDontSee('Client hub')->assertDontSee('Client portal')->assertDontSee('your client');

        Livewire::actingAs($owner)->test(TeamIndex::class)
            ->assertViewHas('roles', fn ($roles) => ! array_key_exists('client', $roles))
            ->set('email', 'someone@plush.test')->set('role', 'client')->call('invite')->assertHasErrors('role');
    }

    public function test_an_agency_sees_client_features_and_an_owner_can_switch(): void
    {
        [$owner, $workspace, $product] = $this->fixture();

        $this->actingAs($owner)->get(route('products.show', [$product, 'tab' => 'hub']))->assertOk()
            ->assertSee('Client hub')->assertSee('Client portal');

        Livewire::actingAs($owner)->test(WorkspaceSettings::class)
            ->assertSet('kind', 'agency')->set('kind', 'store')->call('save')->assertHasNoErrors();
        $this->assertSame('store', $workspace->fresh()->kind);
        $this->assertDatabaseHas('workspace_audit_events', ['workspace_id' => $workspace->id, 'event' => 'workspace_kind_changed']);
    }

    public function test_the_migration_makes_only_workspaces_with_clients_agencies(): void
    {
        [, $withClient] = $this->fixture();
        $withClient->users()->attach(User::factory()->create(), ['role' => 'client', 'brand_id' => $withClient->brands()->value('id')]);
        [, $manyBrands] = $this->fixture();
        $manyBrands->brands()->create(['name' => 'Another']);
        DB::table('workspaces')->update(['kind' => 'agency']);

        (require database_path('migrations/2026_10_24_100000_add_kind_to_workspaces.php'))->up();

        $this->assertSame('agency', $withClient->fresh()->kind);
        $this->assertSame('store', $manyBrands->fresh()->kind);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Northstar Performance']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $brand = $workspace->brands()->create(['name' => 'Harbor & Pine']);
        $product = $brand->products()->create(['name' => 'Everyday Upgrade', 'price' => '$49', 'summary' => 'A useful product.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/products/everyday-upgrade', 'content_hash' => hash('sha256', 'x'.$workspace->id),
            'status' => 'ready', 'extracted_content' => $product->summary, 'extracted_truth' => ['description' => $product->summary],
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Everyday Upgrade Launch',
            'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard',
        ]);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $workspace, $product, $pack];
    }
}
