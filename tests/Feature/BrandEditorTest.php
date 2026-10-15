<?php

namespace Tests\Feature;

use App\Livewire\BrandEditor;
use App\Livewire\ProductHub;
use App\Models\Brand;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

class BrandEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(ProductKnowledge::class, 'available'))->setValue(null, null);
        (new ReflectionProperty(Brand::class, 'profileAvailable'))->setValue(null, null);
    }

    public function test_brands_page_shows_voice_audience_palette_and_the_configured_limit(): void
    {
        config(['campaigns.brand_limit' => 7]);
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create([
            'name' => 'Northline Goods', 'voice' => 'Calm, practical, a little dry.', 'default_audience' => 'Home office workers',
            'palette' => ['#1F3A34', '#E9E1D3'],
        ]);

        $this->actingAs($user)->get(route('brands.index'))
            ->assertOk()
            ->assertSee('Northline Goods')
            ->assertSee('Calm, practical, a little dry.')
            ->assertSee('Home office workers')
            ->assertSee('Brand colour #1F3A34')
            ->assertSee('1 of 7')
            ->assertSee(route('brands.create'));
    }

    public function test_a_brand_is_created_with_voice_audience_and_palette(): void
    {
        [$user, $workspace] = $this->workspaceUser();

        Livewire::actingAs($user)->test(BrandEditor::class)
            ->set('name', 'Kettle & Co.')
            ->set('website', 'https://kettle.example')
            ->set('voice', 'Warm and playful, never cute.')
            ->set('defaultAudience', 'Students and makers')
            ->set('colors.c1', '#3b2e5a')
            ->call('addColor')
            ->set('colors.c2', '#F4E7D4')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('brands.index'));

        $brand = $workspace->brands()->firstOrFail();
        $this->assertSame('Warm and playful, never cute.', $brand->voice);
        $this->assertSame('Students and makers', $brand->default_audience);
        $this->assertSame(['#3B2E5A', '#F4E7D4'], $brand->palette);
        $this->assertSame('#3B2E5A', $brand->primary_color, 'The first palette colour drives banner buttons');
        $this->assertDatabaseHas('workspace_audit_events', ['event' => 'brand_created', 'subject_id' => $brand->id]);
    }

    public function test_palette_colours_must_be_hex_and_are_capped(): void
    {
        [$user] = $this->workspaceUser();

        $component = Livewire::actingAs($user)->test(BrandEditor::class)
            ->set('name', 'Brand')
            ->set('colors.c1', 'red')
            ->call('save')
            ->assertHasErrors('colors.c1');

        $component->set('colors.c1', '#111111')
            ->call('addColor')->call('addColor')->call('addColor')->call('addColor')->call('addColor');
        $this->assertCount(BrandEditor::MAX_COLORS, $component->get('colors'));
    }

    public function test_the_last_colour_can_be_removed_and_an_empty_palette_is_saved(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline', 'primary_color' => '#C7643A', 'palette' => ['#C7643A']]);

        Livewire::actingAs($user)->test(BrandEditor::class, ['brand' => $brand])
            ->assertSee('Remove colour 1')
            ->call('removeColor', 'c1')
            ->assertSet('colors', [])
            ->assertSee('No colours yet.')
            ->call('save')
            ->assertHasNoErrors();

        $brand->refresh();
        $this->assertSame([], $brand->palette);
        $this->assertNull($brand->primary_color, 'Banners fall back to the default accent');
        $this->assertSame([], $brand->colors());

        Livewire::actingAs($user)->test(BrandEditor::class, ['brand' => $brand])
            ->assertSet('colors', [])
            ->call('addColor')
            ->assertSet('colors', ['c1' => '#FFFFFF']);
    }

    public function test_removing_a_colour_never_shifts_another_rows_binding(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline', 'palette' => ['#111111', '#222222', '#333333']]);

        Livewire::actingAs($user)->test(BrandEditor::class, ['brand' => $brand])
            ->call('removeColor', 'c1')
            ->call('removeColor', 'c2')
            ->set('colors.c3', '#444444')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['#444444'], $brand->refresh()->palette);
        $this->assertSame('#444444', $brand->primary_color);
    }

    public function test_a_late_update_from_a_removed_colour_is_dropped(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline', 'palette' => ['#111111', '#222222']]);

        Livewire::actingAs($user)->test(BrandEditor::class, ['brand' => $brand])
            ->call('removeColor', 'c2')
            // A debounced input from the removed row arrives after the removal.
            ->set('colors.c2', '#ABCDEF')
            // Keys the editor never issued, and non-string values, are ignored too.
            ->set('colors.c9', '#ABCDEF')
            ->set('colors.c1', ['#ABCDEF'])
            ->assertSet('colors', ['c1' => ''])
            ->set('colors.c1', '#123456')
            ->call('removeColor', 'c2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['#123456'], $brand->refresh()->palette);
    }

    public function test_an_existing_brand_is_edited_and_its_logo_replaced(): void
    {
        Storage::fake('local');
        config(['campaigns.banners.disk' => 'local']);
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline', 'primary_color' => '#C7643A']);

        Livewire::actingAs($user)->test(BrandEditor::class, ['brand' => $brand])
            ->assertSet('colors', ['c1' => '#C7643A'])
            ->set('voice', 'Short sentences.')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('save')
            ->assertHasNoErrors();

        $brand->refresh();
        $this->assertSame('Short sentences.', $brand->voice);
        $this->assertNotNull($brand->banner_logo_path);
        Storage::disk('local')->assertExists($brand->banner_logo_path);
    }

    public function test_brands_of_another_workspace_cannot_be_edited(): void
    {
        [$user] = $this->workspaceUser();
        $foreign = Workspace::create(['name' => 'Elsewhere'])->brands()->create(['name' => 'Theirs']);

        $this->actingAs($user)->get(route('brands.edit', $foreign))->assertNotFound();
    }

    public function test_new_brands_stop_at_the_configured_limit(): void
    {
        config(['campaigns.brand_limit' => 1]);
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Only']);

        $this->actingAs($user)->get(route('brands.create'))->assertForbidden();
        $this->actingAs($user)->get(route('brands.index'))->assertSee('Brand limit reached');
    }

    public function test_products_inherit_the_brand_audience_and_voice_until_they_set_their_own(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline', 'voice' => 'Calm and dry.', 'default_audience' => 'Home office workers', 'palette' => ['#1F3A34']]);
        $product = $brand->products()->create(['name' => 'Desk Lamp']);
        $knowledge = app(ProductKnowledge::class);
        $knowledge->facts($product);

        [, $page] = $knowledge->prepare($product, ['product_truth' => []]);
        $this->assertSame('Home office workers', $page['team_knowledge']['audience']);
        $this->assertSame(['voice' => 'Calm and dry.', 'palette' => ['#1F3A34']], $page['brand_profile']);

        Livewire::actingAs($user)->test(ProductHub::class, ['product' => $product])
            ->set('tab', 'knowledge')
            ->assertSee('From brand')
            ->assertSee('Inherited from the brand Northline');

        $fingerprint = $knowledge->fingerprint($product);
        $knowledge->confirm($product->facts()->where('key', 'audience')->first(), 'Students', $user);
        [, $page] = $knowledge->prepare($product, ['product_truth' => []]);
        $this->assertSame('Students', $page['team_knowledge']['audience'], 'A product value overrides the brand default');
        $this->assertNotSame($fingerprint, $knowledge->fingerprint($product));

        Livewire::actingAs($user)->test(ProductHub::class, ['product' => $product])
            ->set('tab', 'knowledge')
            ->assertSee('Overrides the Northline default: Home office workers');

        $knowledge->exclude($product->facts()->where('key', 'audience')->first());
        [, $page] = $knowledge->prepare($product, ['product_truth' => []]);
        $this->assertArrayNotHasKey('audience', $page['team_knowledge'] ?? [], 'An excluded audience never falls back to the brand');
    }

    private function workspaceUser(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency Workspace']);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
