<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_open_another_workspaces_pack(): void
    {
        [$owner, $ownerWorkspace] = $this->workspaceUser('Owner Workspace');
        [, $otherWorkspace] = $this->workspaceUser('Other Workspace');
        $brand = $otherWorkspace->brands()->create(['name' => 'Private Brand']);
        $product = $brand->products()->create(['name' => 'Private Product']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/private',
            'content_hash' => hash('sha256', 'private'),
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Private Pack',
        ]);

        Livewire::actingAs($owner)
            ->test(CampaignWorkspace::class, ['pack' => $pack])
            ->assertStatus(404);

        $this->assertSame(0, $ownerWorkspace->brands()->count());
    }

    public function test_library_pages_only_show_the_current_workspace_records(): void
    {
        [$owner, $workspace] = $this->workspaceUser('Owner Workspace');
        [, $otherWorkspace] = $this->workspaceUser('Other Workspace');
        $workspace->brands()->create(['name' => 'Visible Brand']);
        $otherWorkspace->brands()->create(['name' => 'Hidden Brand']);

        $this->actingAs($owner)
            ->get('/brands')
            ->assertOk()
            ->assertSee('Visible Brand')
            ->assertDontSee('Hidden Brand');
    }

    private function workspaceUser(string $workspaceName): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => $workspaceName]);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
