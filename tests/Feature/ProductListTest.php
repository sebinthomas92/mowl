<?php

namespace Tests\Feature;

use App\Livewire\ProductIndex;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductListTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_filtered_by_name_and_shown_as_a_grid(): void
    {
        [$owner, $workspace] = $this->workspace('owner');
        $brand = $workspace->brands()->create(['name' => 'Northline']);
        $brand->products()->create(['name' => 'Fold Reading Stand']);
        $brand->products()->create(['name' => 'Trail Bottle']);

        Livewire::actingAs($owner)->test(ProductIndex::class)
            ->assertSee('Fold Reading Stand')->assertSee('Trail Bottle')
            ->set('q', 'fold')
            ->assertSee('Fold Reading Stand')->assertDontSee('Trail Bottle')
            ->set('q', 'nothing like this')
            ->assertSee('No products match these filters')
            ->call('clearFilters')
            ->set('view', 'grid')
            ->assertSeeHtml('product-grid')
            ->assertSee('Trail Bottle');
    }

    public function test_needs_attention_shows_products_with_a_stale_source(): void
    {
        [$owner, $workspace] = $this->workspace('owner');
        $brand = $workspace->brands()->create(['name' => 'Northline']);
        $stale = $brand->products()->create(['name' => 'Old Lamp']);
        $fresh = $brand->products()->create(['name' => 'New Lamp']);
        $this->snapshot($stale, now()->subDays(30));
        $this->snapshot($fresh, now()->subDays(40));
        $fresh->sourceSnapshots()->first()->forceFill(['fetched_at' => now()->subDay()])->save();

        Livewire::actingAs($owner)->test(ProductIndex::class)
            ->assertSee('Stale')
            ->set('show', 'attention')
            ->assertSee('Old Lamp')
            ->assertDontSee('New Lamp');
    }

    public function test_an_owner_can_archive_and_restore_a_product_without_losing_it(): void
    {
        [$owner, $workspace] = $this->workspace('owner');
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => 'Desk Lamp']);

        $component = Livewire::actingAs($owner)->test(ProductIndex::class)
            ->call('archive', $product->id)
            ->assertSee('Desk Lamp is archived')
            ->assertSee('Archived · ');
        $this->assertNotNull($product->fresh()->archived_at);
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        $component->set('notice', null)->set('show', 'active')->assertDontSee('Desk Lamp');
        $component->set('show', 'archived')->assertSee('Desk Lamp')->call('restore', $product->id);
        $this->assertNull($product->fresh()->archived_at);

        $this->actingAs($owner)->get(route('products.show', $product))->assertOk();
    }

    public function test_reviewers_cannot_archive_and_other_workspaces_are_out_of_reach(): void
    {
        [$owner, $workspace] = $this->workspace('owner');
        $reviewer = User::factory()->create();
        $workspace->users()->attach($reviewer, ['role' => 'reviewer']);
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => 'Desk Lamp']);
        [$stranger] = $this->workspace('owner');

        Livewire::actingAs($reviewer)->test(ProductIndex::class)->call('archive', $product->id)->assertForbidden();
        Livewire::actingAs($stranger)->test(ProductIndex::class)->call('archive', $product->id)->assertNotFound();
        $this->assertNull($product->fresh()->archived_at);
    }

    private function workspace(string $role): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency '.$user->id]);
        $workspace->users()->attach($user, ['role' => $role]);

        return [$user, $workspace];
    }

    private function snapshot(Product $product, $at): void
    {
        $snapshot = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/'.$product->id, 'content_hash' => hash('sha256', (string) $product->id),
            'status' => 'ready', 'extracted_content' => 'Page', 'extracted_truth' => ['name' => $product->name],
        ]);
        $snapshot->forceFill(['created_at' => $at])->save();
    }
}
