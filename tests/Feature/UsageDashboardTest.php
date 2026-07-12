<?php

namespace Tests\Feature;

use App\Livewire\UsageIndex;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsageDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_dashboard_summarizes_only_the_current_workspace(): void
    {
        [$user, $workspace] = $this->workspaceUser('Visible Agency');
        [, $otherWorkspace] = $this->workspaceUser('Other Agency');
        $workspace->credits()->create(['amount' => 50, 'event' => 'beta_allocation', 'description' => 'Visible allocation']);
        $workspace->credits()->create(['amount' => -3, 'event' => 'generation', 'description' => 'Visible spend']);
        $otherWorkspace->credits()->create(['amount' => 999, 'event' => 'beta_allocation', 'description' => 'Hidden allocation']);
        $this->job($workspace, 0.18, false);
        $this->job($workspace, 0.61, true);
        $this->job($otherWorkspace, 50, true);

        Livewire::actingAs($user)
            ->test(UsageIndex::class)
            ->assertViewHas('creditBalance', 47)
            ->assertViewHas('creditsSpent', 3)
            ->assertViewHas('totalCost', 0.79)
            ->assertViewHas('costAlerts', 1)
            ->assertSee('Visible spend')
            ->assertDontSee('Hidden allocation');
    }

    public function test_usage_dashboard_requires_authentication(): void
    {
        $this->get('/usage')->assertRedirect('/login');
    }

    private function workspaceUser(string $name): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => $name]);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }

    private function job(Workspace $workspace, float $cost, bool $alert): void
    {
        $brand = $workspace->brands()->create(['name' => 'Usage Brand']);
        $product = $brand->products()->create(['name' => 'Usage Product']);
        $source = $product->sourceSnapshots()->create([
            'type' => 'product_page',
            'url' => 'https://example.com/product',
            'content_hash' => hash('sha256', (string) $cost),
            'status' => 'ready',
        ]);
        $pack = $product->campaignPacks()->create([
            'source_snapshot_id' => $source->id,
            'name' => 'Usage Pack',
            'status' => 'approved',
        ]);

        $workspace->generationJobs()->create([
            'campaign_pack_id' => $pack->id,
            'source_snapshot_id' => $source->id,
            'status' => 'completed',
            'phase' => 'completed',
            'provider' => 'mock',
            'model' => 'deterministic',
            'analysis_mode' => 'standard',
            'credit_cost' => 1,
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'estimated_cost' => $cost,
            'cost_alert' => $alert,
        ]);
    }
}
