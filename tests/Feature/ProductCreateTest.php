<?php

namespace Tests\Feature;

use App\Jobs\GenerateCampaignPack;
use App\Livewire\ProductCreate;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

class ProductCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(ProductKnowledge::class, 'available'))->setValue(null, null);
        (new ReflectionProperty(Brand::class, 'profileAvailable'))->setValue(null, null);
    }

    public function test_the_add_product_screen_renders(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Northline', 'default_audience' => 'Home office workers']);

        $this->actingAs($user)->get(route('products.create'))
            ->assertOk()
            ->assertSee('Add a product')
            ->assertSee('From a product URL')
            ->assertSee('Saving never starts generation.');
    }

    public function test_a_product_is_imported_from_its_page_confirmed_and_saved_without_generating(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline']);
        $this->fakePage();

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->assertSet('brandId', (string) $brand->id)
            ->set('productUrl', '93.184.216.34/store/canvas-tote')
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('importedUrl', 'https://93.184.216.34/store/canvas-tote')
            ->assertSet('name', 'Canvas Tote')
            ->assertSet('price', 'USD 89')
            ->assertSet('description', 'A roomy everyday carry tote.')
            ->assertSee('Confirm what we found')
            ->set('price', '$79.00')
            ->set('audience', 'Commuters')
            ->set('benefits', "Fits a laptop\nMachine washable")
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('products.show', Product::firstOrFail()));

        $product = Product::firstOrFail();
        $this->assertSame('$79.00', $product->price);
        $this->assertSame(1, $product->sourceSnapshots()->count());
        $this->assertSame(0, $product->campaignPacks()->count());
        Queue::assertNotPushed(GenerateCampaignPack::class);

        $facts = $product->facts()->get()->keyBy('key');
        $this->assertSame('ok', $facts['price']->status, 'A price the person typed is confirmed, not a conflict');
        $this->assertSame('$79.00', $facts['price']->confirmed_value);
        $this->assertSame('USD 89', $facts['price']->source_value);
        $this->assertSame($user->id, $facts['price']->confirmed_by_user_id);
        $this->assertSame('Commuters', $facts['audience']->confirmed_value);
        $this->assertSame("Fits a laptop\nMachine washable", $facts['benefits']->confirmed_value);
        $this->assertSame('missing', $facts['differentiators']->status);
        $this->assertDatabaseHas('workspace_audit_events', ['event' => 'product_created', 'subject_id' => $product->id]);
    }

    public function test_an_address_already_used_in_the_workspace_is_flagged_until_the_person_keeps_both(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->workspaceUser();
        $brand = $workspace->brands()->create(['name' => 'Northline']);
        $existing = $brand->products()->create(['name' => 'Canvas Tote']);
        $existing->sourceSnapshots()->create([
            'url' => 'http://www.93.184.216.34/products/canvas-tote/', 'content_hash' => 'x', 'status' => 'ready',
            'extracted_content' => 'Page', 'extracted_truth' => [],
        ]);
        $this->fakePage();

        $component = Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('productUrl', 'https://93.184.216.34/store/canvas-tote')
            ->call('import')
            ->assertSet('duplicateId', $existing->id)
            ->assertSee('Possible duplicate.')
            ->call('save')
            ->assertHasErrors('productUrl');
        $this->assertSame(1, Product::count());

        $component->set('keepBoth', true)->call('save')->assertHasNoErrors();
        $this->assertSame(2, Product::count());
    }

    public function test_a_duplicate_in_another_workspace_is_not_flagged(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Northline']);
        $other = Workspace::create(['name' => 'Elsewhere']);
        $other->brands()->create(['name' => 'Other'])->products()->create(['name' => 'Tote'])->sourceSnapshots()->create([
            'url' => 'https://93.184.216.34/products/canvas-tote', 'content_hash' => 'x', 'status' => 'ready',
            'extracted_content' => 'Page', 'extracted_truth' => [],
        ]);
        $this->fakePage();

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('productUrl', 'https://93.184.216.34/store/canvas-tote')
            ->call('import')
            ->assertSet('duplicateId', null);
    }

    public function test_saving_from_a_url_requires_an_import_of_that_exact_address(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Northline']);
        $this->fakePage();

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('productUrl', 'https://93.184.216.34/store/canvas-tote')
            ->call('import')
            ->set('productUrl', 'https://93.184.216.34/store/other')
            ->assertSet('importedUrl', '')
            ->set('name', 'Something')
            ->call('save')
            ->assertHasErrors('productUrl');

        $this->assertSame(0, Product::count());
    }

    public function test_an_unreadable_page_keeps_the_draft_and_offers_manual_entry(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Northline']);
        Http::fake(['93.184.216.34/*' => Http::response('denied', 403)]);

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('productUrl', 'https://93.184.216.34/store/canvas-tote')
            ->call('import')
            ->assertHasErrors('productUrl')
            ->assertSee('Add manually')
            ->call('setMode', 'manual')
            ->assertSet('productUrl', 'https://93.184.216.34/store/canvas-tote');
    }

    public function test_a_product_can_be_added_manually_with_a_new_brand(): void
    {
        [$user, $workspace] = $this->workspaceUser();

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->assertSet('brandId', 'new')
            ->call('setMode', 'manual')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->set('name', 'Desk Mat')
            ->set('price', '$24')
            ->set('benefits', 'Non-slip backing')
            ->set('productUrl', 'https://shop.example/desk-mat')
            ->call('save')
            ->assertHasErrors('newBrandName')
            ->set('newBrandName', 'Kettle & Co.')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::with('brand')->firstOrFail();
        $this->assertSame('Kettle & Co.', $product->brand->name);
        $this->assertSame($workspace->id, $product->brand->workspace_id);
        $this->assertSame(0, $product->sourceSnapshots()->count());
        $this->assertSame('https://shop.example/desk-mat', $product->resourceLinks()->where('kind', 'product_page')->value('url'));
        $facts = $product->facts()->get()->keyBy('key');
        $this->assertSame('$24', $facts['price']->confirmed_value);
        $this->assertSame('Non-slip backing', $facts['benefits']->confirmed_value);
        $this->assertSame('missing', $facts['audience']->status);
    }

    public function test_a_new_brand_respects_the_configured_limit(): void
    {
        config(['campaigns.brand_limit' => 1]);
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Only']);

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->call('setMode', 'manual')
            ->set('brandId', 'new')
            ->set('newBrandName', 'Second')
            ->set('name', 'Desk Mat')
            ->call('save')
            ->assertHasErrors('newBrandName');

        $this->assertSame(1, $workspace->brands()->count());
    }

    public function test_a_brand_from_another_workspace_cannot_be_used(): void
    {
        [$user, $workspace] = $this->workspaceUser();
        $workspace->brands()->create(['name' => 'Mine']);
        $foreign = Workspace::create(['name' => 'Elsewhere'])->brands()->create(['name' => 'Theirs']);

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->call('setMode', 'manual')
            ->set('brandId', (string) $foreign->id)
            ->set('name', 'Desk Mat')
            ->call('save')
            ->assertHasErrors('brandId');

        $this->assertSame(0, Product::count());
    }

    public function test_address_keys_ignore_scheme_www_trailing_slash_and_fragment(): void
    {
        $this->assertSame('shop.example/item?a=1', ProductCreate::addressKey('https://www.Shop.example/item/?a=1#top'));
        $this->assertSame('shop.example/item?a=1', ProductCreate::addressKey('http://shop.example/item?a=1'));
        $this->assertNull(ProductCreate::addressKey('not a url'));
    }

    private function fakePage(): void
    {
        Http::fake([
            '93.184.216.34/media/*' => Http::response('', 404),
            '93.184.216.34/*' => Http::response(<<<'HTML'
                <html><head><title>Fallback page title</title><link rel="canonical" href="/products/canvas-tote"><script type="application/ld+json">{"@type":"Product","name":"Canvas Tote","description":"A roomy everyday carry tote.","image":"/media/tote.png","offers":{"price":"89","priceCurrency":"USD"}}</script></head><body>Product details</body></html>
                HTML, 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function workspaceUser(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency Workspace']);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
