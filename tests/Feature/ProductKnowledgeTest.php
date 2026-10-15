<?php

namespace Tests\Feature;

use App\Livewire\ProductHub;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MockCampaignPackGenerator;
use App\Services\ProductKnowledge;
use App\Services\ProductPageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class ProductKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_facts_are_seeded_from_the_product_and_its_source(): void
    {
        [, , $product] = $this->fixture();

        $facts = app(ProductKnowledge::class)->facts($product)->keyBy('key');

        $this->assertSame(array_keys(ProductKnowledge::FACTS), $facts->keys()->all());
        $this->assertSame('ok', $facts['name']->status);
        $this->assertSame('ok', $facts['price']->status, '$54.00 and USD 54 are the same price');
        $this->assertSame('$54.00', $facts['price']->confirmed_value);
        $this->assertSame('In Stock', $facts['availability']->confirmed_value);
        $this->assertSame('missing', $facts['audience']->status);
    }

    public function test_a_changed_source_becomes_a_conflict_and_never_overwrites_the_confirmed_value(): void
    {
        [$owner, , $product] = $this->fixture();
        $knowledge = app(ProductKnowledge::class);
        $knowledge->facts($product);

        $conflicts = $knowledge->syncFromSource($product, $this->snapshot($product, ['name' => 'Desk Lamp', 'price' => '64.00', 'currency' => 'USD']));

        $this->assertSame(['price'], $conflicts);
        $price = $product->facts()->where('key', 'price')->first();
        $this->assertSame('conflict', $price->status);
        $this->assertSame('$54.00', $price->confirmed_value);
        $this->assertSame('USD 64.00', $price->source_value);
        $this->assertSame('$54.00', $product->fresh()->price);

        $knowledge->acceptSource($price, $owner);
        $this->assertSame('ok', $price->fresh()->status);
        $this->assertSame('USD 64.00', $product->fresh()->price);
    }

    public function test_an_unresolved_price_conflict_sends_the_page_price_not_the_saved_one(): void
    {
        [, , $product] = $this->fixture();
        $knowledge = app(ProductKnowledge::class);
        $knowledge->facts($product);
        $knowledge->syncFromSource($product, $this->snapshot($product, ['name' => 'Desk Lamp', 'price' => '64.00', 'currency' => 'USD']));

        [$subject, $page] = $knowledge->prepare($product->fresh(), ['product_truth' => ['name' => 'Desk Lamp', 'price' => '64.00', 'currency' => 'USD']]);

        $this->assertSame('USD 64.00', $subject->price);
        $this->assertArrayNotHasKey('price', $page['team_knowledge'] ?? []);
        $this->assertSame('$54.00', $product->fresh()->price);
    }

    public function test_an_unresolved_conflict_uses_the_page_name_and_drops_a_price_the_page_no_longer_shows(): void
    {
        [, , $product] = $this->fixture();
        $knowledge = app(ProductKnowledge::class);
        $knowledge->facts($product);
        $knowledge->syncFromSource($product, $this->snapshot($product, ['name' => 'Desk Lamp Pro', 'price' => '64.00', 'currency' => 'USD']));
        $this->assertSame('conflict', $product->facts()->where('key', 'name')->value('status'));

        [$subject, $page] = $knowledge->prepare($product->fresh(), ['product_truth' => ['name' => 'Desk Lamp Pro']]);

        $this->assertSame('Desk Lamp Pro', $subject->name);
        $this->assertNull($subject->price, 'The saved $54.00 is not sent once the page shows no price');
        $this->assertArrayNotHasKey('price', $page['team_knowledge'] ?? []);
    }

    public function test_team_can_confirm_keep_exclude_and_restore_facts(): void
    {
        [$owner, , $product] = $this->fixture();
        app(ProductKnowledge::class)->facts($product);
        app(ProductKnowledge::class)->syncFromSource($product, $this->snapshot($product, ['name' => 'Desk Lamp', 'price' => '70']));

        $hub = Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])
            ->set('tab', 'knowledge')
            ->assertSee('The source changed.')
            ->assertSee('Accept source')
            ->call('keepConfirmed', 'price')
            ->assertDontSee('The source changed.')
            ->call('editFact', 'audience')
            ->set('factValue', 'Home office workers')
            ->call('saveFact')
            ->assertHasNoErrors()
            ->assertSee('Home office workers')
            ->call('editFact', 'name')
            ->set('factValue', '')
            ->call('saveFact')
            ->assertHasErrors(['factValue'])
            ->call('cancelEdit')
            ->call('excludeFact', 'availability');

        $facts = $product->facts()->get()->keyBy('key');
        $this->assertSame('ok', $facts['price']->status);
        $this->assertSame('$54.00', $facts['price']->confirmed_value);
        $this->assertSame($owner->id, $facts['audience']->confirmed_by_user_id);
        $this->assertSame('excluded', $facts['availability']->status);
        $this->assertSame('Desk Lamp', $product->fresh()->name);

        // The page's own truth stays as the page said it; differing confirmed values travel with team provenance.
        [$subject, $page] = app(ProductKnowledge::class)->prepare($product->fresh(), ['product_truth' => ['name' => 'Desk Lamp', 'price' => '70', 'currency' => 'USD', 'availability' => 'InStock']]);
        $this->assertSame('70', $page['product_truth']['price']);
        $this->assertArrayNotHasKey('availability', $page['product_truth']);
        $this->assertSame(['price' => '$54.00', 'description' => 'A warm desk lamp.', 'audience' => 'Home office workers'], $page['team_knowledge'], 'values the page input does not carry are team-sourced');
        $this->assertSame('$54.00', $subject->price);

        $hub->call('restoreFact', 'availability');
        $this->assertSame('ok', $product->facts()->where('key', 'availability')->value('status'));
        $this->assertSame(4, $product->brand->workspace->auditEvents()->where('event', 'like', 'product_fact_%')->count());
    }

    public function test_excluded_facts_are_scrubbed_from_every_generator_input(): void
    {
        [$owner, , $product] = $this->fixture();
        $knowledge = app(ProductKnowledge::class);
        $facts = $knowledge->facts($product)->keyBy('key');
        $knowledge->exclude($facts['price']);
        $knowledge->exclude($facts['description']);
        $knowledge->confirm($facts['audience'], 'Home office workers', $owner);

        [$subject, $page] = $knowledge->prepare($product->fresh(), [
            'product_truth' => ['name' => 'Desk Lamp', 'price' => '54', 'currency' => 'USD', 'description' => 'A warm desk lamp.'],
            'description' => 'A warm desk lamp.',
            'content' => 'Desk Lamp. A warm desk lamp. Only $54.00 today, USD 54 shipped.',
        ]);

        $this->assertNull($subject->price);
        $this->assertNull($subject->summary);
        $this->assertSame('$54.00', $product->fresh()->price, 'the stored product is untouched');
        $this->assertArrayNotHasKey('price', $page['product_truth']);
        $this->assertArrayNotHasKey('description', $page['product_truth']);
        $this->assertArrayNotHasKey('description', $page);
        $this->assertStringNotContainsString('54', $page['content']);
        $this->assertStringNotContainsString('warm desk lamp', $page['content']);
        $this->assertSame(['availability' => 'In Stock', 'audience' => 'Home office workers'], $page['team_knowledge']);

        $result = app(MockCampaignPackGenerator::class)->generate($subject, $product->sourceSnapshots()->first(), $page);
        $this->assertSame('Price not supplied', data_get($result->content, 'product_truth.price'));
        $this->assertSame(['Home office workers'], data_get($result->content, 'marketing_hub.key_messaging.audiences'));
    }

    public function test_refresh_source_records_a_new_snapshot_and_reports_changes(): void
    {
        [$owner, , $product] = $this->fixture();
        $this->mock(ProductPageFetcher::class)->shouldReceive('fetch')->once()->with('https://example.com/lamp')->andReturn([
            'url' => 'https://example.com/lamp', 'content_hash' => hash('sha256', 'v2'), 'content' => 'New page',
            'product_truth' => ['name' => 'Desk Lamp', 'price' => '59', 'currency' => 'USD', 'availability' => 'https://schema.org/InStock'],
        ]);

        Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])
            ->set('tab', 'knowledge')
            ->call('refreshSource')
            ->assertSee('1 fact needs your decision');

        $this->assertSame(2, $product->sourceSnapshots()->count());
        $this->assertSame('conflict', $product->facts()->where('key', 'price')->value('status'));
        $this->assertTrue($product->brand->workspace->auditEvents()->where('event', 'product_source_refreshed')->exists());
    }

    public function test_failed_refresh_keeps_the_existing_source(): void
    {
        [$owner, , $product] = $this->fixture();
        $this->mock(ProductPageFetcher::class)->shouldReceive('fetch')->andThrow(new RuntimeException('blocked'));

        Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])
            ->call('refreshSource')
            ->assertSee('We could not read the product page.');

        $this->assertSame(1, $product->sourceSnapshots()->count());
    }

    public function test_tabs_render_and_other_workspaces_cannot_change_facts(): void
    {
        [$owner, , $product] = $this->fixture();

        foreach (['overview' => 'What it is', 'knowledge' => 'Your confirmed value', 'content' => 'No content yet', 'hub' => 'Product playbook', 'packs' => 'Desk Lamp launch pack'] as $tab => $text) {
            $this->actingAs($owner)->get(route('products.show', [$product, 'tab' => $tab]))->assertOk()->assertSee($text);
        }
        $this->actingAs($owner)->get(route('products.show', [$product, 'tab' => 'nope']))->assertOk()->assertSee('What it is');

        $outsider = User::factory()->create();
        Workspace::create(['name' => 'Other'])->users()->attach($outsider, ['role' => 'owner']);
        $this->actingAs($outsider)->get(route('products.show', [$product, 'tab' => 'knowledge']))->assertNotFound();
    }

    public function test_product_page_works_before_the_product_facts_migration_runs(): void
    {
        [$owner, , $product] = $this->fixture();
        Schema::drop('product_facts');
        (new ReflectionProperty(ProductKnowledge::class, 'available'))->setValue(null, null);

        try {
            $this->actingAs($owner)->get(route('products.show', $product))->assertOk()->assertSee('Knowledge turns on once');
            $this->actingAs($owner)->get(route('products.show', [$product, 'tab' => 'knowledge']))->assertOk()->assertSee('Knowledge turns on once');
        } finally {
            (new ReflectionProperty(ProductKnowledge::class, 'available'))->setValue(null, null);
        }
    }

    private function fixture(): array
    {
        (new ReflectionProperty(ProductKnowledge::class, 'available'))->setValue(null, null);
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Northline Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => 'Desk Lamp', 'price' => '$54.00', 'summary' => 'A warm desk lamp.']);
        $source = $this->snapshot($product, ['name' => 'Desk Lamp', 'price' => '54', 'currency' => 'USD', 'description' => 'A warm desk lamp.', 'availability' => 'https://schema.org/InStock']);
        $product->campaignPacks()->create(['source_snapshot_id' => $source->id, 'name' => 'Desk Lamp launch pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);

        return [$owner, $workspace, $product];
    }

    private function snapshot(Product $product, array $truth)
    {
        return $product->sourceSnapshots()->create([
            'url' => 'https://example.com/lamp', 'content_hash' => hash('sha256', json_encode($truth)),
            'status' => 'ready', 'extracted_content' => 'Page', 'extracted_truth' => $truth,
        ]);
    }
}
