<?php

namespace Tests\Feature;

use App\Livewire\PageEditor;
use App\Livewire\ProductPages;
use App\Models\CampaignPack;
use App\Models\Page;
use App\Models\PageGeneration;
use App\Models\ProcessingCacheEntry;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Services\Pages\Document\Document;
use App\Services\Pages\PageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PageEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_editor_opens_on_the_page_and_its_canvas_is_the_page_as_it_will_publish(): void
    {
        [$owner, , $product] = $this->fixture('Lamp </script><script>alert(1)</script>');
        $page = app(PageBuilder::class)->start($product, $owner);

        $response = $this->actingAs($owner)->get(route('pages.edit', [$product, $page]))->assertOk()
            ->assertSee('data-pe-config', false)->assertSee('Publish')
            ->assertSee('class="pe-frame" sandbox="allow-same-origin"', false);
        $this->assertStringNotContainsString('</script><script>alert(1)', $response->getContent(), 'Product text in the editor data cannot end its script tag.');

        $editor = $this->editor($owner, $page);
        $document = $page->document;
        $hero = array_search('product_hero', array_column($document['sections'], 'type'), true);
        $document['sections'][$hero]['settings']['title'] = '<b onclick="steal()">Reading lamp</b>';
        $document['sections'][$hero]['hidden'] = true;
        $html = $this->returned($editor->call('preview', $document));

        $this->assertStringContainsString('data-mowl-section="'.$document['sections'][$hero]['id'].'"', $html, 'The canvas has editing hooks.');
        $this->assertStringContainsString('data-mowl-field="title"', $html);
        $this->assertStringContainsString('Reading lamp', $html, 'Hidden sections still show, faded, while editing.');
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<!-- wp:html -->', $html);
    }

    public function test_autosave_refuses_an_older_revision_and_keeps_only_what_the_page_may_hold(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        [, , $foreignProduct] = $this->fixture('Foreign');
        $foreign = $foreignProduct->mediaAssets()->create([
            'workspace_id' => $foreignProduct->brand->workspace_id, 'type' => 'image', 'disk' => 'local', 'path' => 'x.png', 'original_name' => 'x.png',
            'mime_type' => 'image/png', 'size_bytes' => 1, 'content_hash' => 'foreign', 'status' => 'ready',
        ]);
        $page = app(PageBuilder::class)->start($product, $owner);
        $editor = $this->editor($owner, $page);

        $document = $page->document;
        $document['sections'][] = ['type' => 'not_a_section', 'settings' => []];
        $document['sections'][] = ['id' => 'snewgallery', 'type' => 'gallery', 'settings' => ['images' => [['kind' => 'media', 'id' => $foreign->id, 'alt' => 'Not ours']], 'columns' => '9']];
        $document['confirmations'] = [sha1('forged') => ['by' => $owner->id, 'name' => 'Forged']];
        $saved = $this->returned($editor->call('save', $document, 1));

        $this->assertTrue($saved['ok']);
        $this->assertSame(2, $saved['revision']);
        $page->refresh();
        $gallery = collect($page->document['sections'])->firstWhere('id', 'snewgallery');
        $this->assertNotNull($gallery);
        $this->assertSame([], $gallery['settings']['images'], 'Images from another workspace are dropped.');
        $this->assertSame('3', $gallery['settings']['columns'], 'An unknown option falls back to the default.');
        $this->assertNotContains('not_a_section', array_column($page->document['sections'], 'type'));
        $this->assertSame([], $page->document['confirmations'], 'Only the server writes confirmations.');

        $stale = $this->returned($editor->call('save', $document, 1));
        $this->assertFalse($stale['ok']);
        $this->assertTrue($stale['conflict']);
        $this->assertSame('This page changed in another tab or by the AI. Reload to get the latest version.', $stale['message']);
        $this->assertSame(2, $page->fresh()->document_revision);

        $details = $this->returned($editor->call('saveDetails', 'Summer page', 'Summer Page!'));
        $this->assertSame(['slug' => ['Use lowercase letters, numbers and dashes.']], $details['errors']);
        $this->assertTrue($this->returned($editor->call('saveDetails', 'Summer page', 'summer-page'))['ok']);
        $this->assertSame(['Summer page', 'summer-page'], [$page->fresh()->name, $page->fresh()->slug]);
        $this->assertSame(1, Page::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_new_sections_and_fresh_starts_come_filled_from_the_product(): void
    {
        [$owner, , $product] = $this->fixture();
        $page = app(PageBuilder::class)->start($product, $owner);
        $editor = $this->editor($owner, $page);

        $faq = $this->returned($editor->call('starter', 'faq', $page->document));
        $this->assertSame('faq', $faq['type']);
        $this->assertMatchesRegularExpression('/^s[a-z0-9]{8}$/', $faq['id']);
        $this->assertSame('What is Desk Lamp?', $faq['settings']['items'][0]['question']);
        $this->assertSame('A warm reading lamp for small desks.', $faq['settings']['items'][0]['answer']);
        $this->assertNull($this->returned($editor->call('starter', 'not_a_section', $page->document)));

        $stats = $this->returned($editor->call('starter', 'stats', $page->document));
        $this->assertSame('', $stats['settings']['items'][0]['value'] ?? '', 'Proof the product has not given stays empty for the team to fill.');

        $tech = $this->returned($editor->call('templateDocument', 'tech'));
        $this->assertSame('product_hero', $tech['sections'][0]['type']);
        $this->assertSame(1280, $tech['theme']['width']);
        $this->assertNull($this->returned($editor->call('templateDocument', 'nope')));
    }

    public function test_images_are_uploaded_into_the_product_and_can_go_on_the_page(): void
    {
        Storage::fake(config('campaigns.banners.disk'));
        [$owner, , $product] = $this->fixture();
        $page = app(PageBuilder::class)->start($product, $owner);
        $editor = $this->editor($owner, $page);

        $result = $this->returned($editor->set('upload', UploadedFile::fake()->image('lamp.jpg', 800, 600))->call('storeUpload'));
        $this->assertTrue($result['ok']);
        $asset = $product->mediaAssets()->sole();
        $this->assertSame("media-{$asset->id}", $result['key']);
        $this->assertSame(['origin' => 'page_upload', 'width' => 800, 'height' => 600], $asset->metadata);
        $this->assertSame('Uploaded image', collect($result['images'])->firstWhere('key', $result['key'])['label']);
        $this->assertSame(route('products.hub-media', [$product, $asset]), collect($result['images'])->firstWhere('key', $result['key'])['url']);
        Storage::disk(config('campaigns.banners.disk'))->assertExists($asset->path);

        $again = $this->returned($editor->set('upload', UploadedFile::fake()->image('lamp.jpg', 800, 600))->call('storeUpload'));
        $this->assertSame($result['key'], $again['key'], 'The same image uploaded twice is stored once.');
        $this->assertSame(1, $product->mediaAssets()->count());

        $refused = $this->returned($editor->set('upload', UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'))->call('storeUpload'));
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('image', $refused['message']);

        $document = $page->document;
        $document['sections'][] = Document::section('gallery', ['images' => [['kind' => 'media', 'id' => $asset->id, 'alt' => 'The lamp lit']]]);
        $this->assertTrue($this->returned($editor->call('save', $document, $page->document_revision))['ok']);
        $saved = collect($page->fresh()->document['sections'])->last();
        $this->assertSame([['kind' => 'media', 'id' => $asset->id, 'key' => "media-{$asset->id}", 'alt' => 'The lamp lit']], $saved['settings']['images']);
        $this->assertStringContainsString('alt="The lamp lit"', $this->returned($editor->call('preview', $page->fresh()->document)));
    }

    public function test_more_product_photos_come_from_the_product_page(): void
    {
        Storage::fake(config('campaigns.banners.disk'));
        [$owner, , $product] = $this->fixture();
        $page = app(PageBuilder::class)->start($product, $owner);
        $source = $product->sourceSnapshots()->sole();
        ProcessingCacheEntry::create([
            'cache_key' => hash('sha256', 'source-extraction:v1:'.$source->content_hash), 'stage' => 'source_extraction', 'content_hash' => $source->content_hash,
            'payload' => ['gallery_images' => ['https://93.184.216.34/uploads/front.png', 'https://93.184.216.34/uploads/thumb.png', 'https://93.184.216.34/uploads/side.png']],
        ]);
        Http::fake([
            '93.184.216.34/uploads/front.png' => Http::response(self::png(900, 900), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/thumb.png' => Http::response(self::png(90, 90), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/side.png' => Http::response(self::png(1000, 800), 200, ['Content-Type' => 'image/png']),
        ]);
        $editor = $this->editor($owner, $page);

        $result = $this->returned($editor->call('importPhotos'));
        $this->assertSame('Added 2 photos from the product page.', $result['message'], 'Thumbnails are skipped.');
        $this->assertSame(['Product page photo', 'Product page photo'], array_column($result['images'], 'label'));
        $this->assertSame('No new photos: the product page photos are already here.', $this->returned($editor->call('importPhotos'))['message']);

        $source->update(['status' => 'failed']);
        $this->assertSame('This product has no product page to take photos from. Add its page address on the Overview tab.', $this->returned($editor->call('importPhotos'))['message']);
    }

    public function test_people_who_cannot_edit_get_the_preview_and_strangers_get_nothing(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        [$stranger] = $this->fixture('Stranger');
        $page = app(PageBuilder::class)->start($product, $owner);
        $reviewer = User::factory()->create();
        $workspace->users()->attach($reviewer, ['role' => 'reviewer']);
        $client = User::factory()->create();
        $workspace->users()->attach($client, ['role' => 'client', 'brand_id' => $product->brand_id]);
        $otherBrand = $workspace->brands()->create(['name' => 'Other brand']);
        $scoped = User::factory()->create();
        $workspace->users()->attach($scoped, ['role' => 'member', 'brand_id' => $otherBrand->id]);

        $this->actingAs($reviewer)->get(route('pages.edit', [$product, $page]))->assertRedirect(route('pages.preview', [$product, $page]));
        Livewire::actingAs($reviewer)->test(PageEditor::class, ['product' => $product, 'page' => $page])->assertRedirect(route('pages.preview', [$product, $page]));
        $this->actingAs($reviewer)->get(route('pages.preview', [$product, $page]))->assertOk()->assertSee('Back to the product')->assertDontSee('Back to the editor');
        foreach ([$client, $scoped, $stranger] as $user) {
            $this->actingAs($user)->get(route('pages.edit', [$product, $page]))->assertNotFound();
            $this->actingAs($user)->get(route('pages.preview', [$product, $page]))->assertNotFound();
            $this->actingAs($user)->get(route('pages.template-preview', [$product, 'studio']))->assertNotFound();
        }
        [, , $otherProduct] = $this->fixture('Elsewhere', $workspace);
        $this->actingAs($owner)->get('/products/'.$otherProduct->id.'/pages/'.$page->id.'/edit')->assertNotFound();
    }

    public function test_the_draft_preview_shows_the_page_alone_and_templates_preview_from_the_product(): void
    {
        [$owner, , $product] = $this->fixture();
        $page = app(PageBuilder::class)->start($product, $owner);
        $document = $page->document;
        $faq = array_search('faq', array_column($document['sections'], 'type'), true);
        $document['sections'][$faq]['hidden'] = true;
        app(PageBuilder::class)->saveDocument($page, $document, 1);

        $preview = $this->actingAs($owner)->get(route('pages.preview', [$product, $page]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Draft v1 of Launch page')->assertSee('Back to the editor')
            ->assertSee('class="mowl-page alignfull"', false)->assertDontSee('data-mowl-section', false);
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString("script-src 'none'", (string) $preview->headers->get('Content-Security-Policy'), 'Previews show outside text, so no script may run.');
        $this->assertStringNotContainsString('What is Desk Lamp?', $preview->getContent(), 'Hidden sections are not published, so the preview leaves them out.');
        $this->actingAs($owner)->get(route('pages.preview', [$product, $page, 'thumb' => 1]))->assertOk()->assertDontSee('Back to the editor');

        foreach (['studio', 'editorial', 'bold', 'soft', 'fresh', 'tech'] as $template) {
            $this->actingAs($owner)->get(route('pages.template-preview', [$product, $template]))->assertOk()->assertSee('Desk Lamp')
                ->assertHeader('Content-Security-Policy', "script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        }
        $this->actingAs($owner)->get(route('pages.template-preview', [$product, 'nope']))->assertNotFound();
        $this->assertSame(1, Page::query()->count(), 'Previewing a template creates nothing.');
    }

    public function test_a_page_starts_from_the_gallery_and_the_ai_write_runs_once(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);

        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $product->id])
            ->assertSee('Pick a template')->assertSee('Use Bold')
            ->call('create', 'bold', false)
            ->assertRedirect(route('pages.edit', [$product, Page::query()->latest('id')->first()]));
        $first = Page::query()->sole();
        $this->assertSame(['bold', 'Launch page', 'desk-lamp-launch'], [$first->template, $first->name, $first->slug]);

        Livewire::actingAs($member)->test(ProductPages::class, ['productId' => $product->id])
            ->call('create', 'soft', true);
        $second = Page::query()->latest('id')->first();
        $this->assertSame(['soft', 'Page 2'], [$second->template, $second->name]);
        $this->assertContains('steps', array_column($second->document['sections'], 'type'), 'A page the AI will write keeps every planned section.');
        $this->assertNotContains('steps', array_column($first->document['sections'], 'type'));

        $config = fn (Page $page, array $query = []) => json_decode(html_entity_decode((string) preg_replace('#^.*<script type="application/json" data-pe-config>(.*?)</script>.*$#s', '$1',
            $this->actingAs($member)->get(route('pages.edit', [$product, $page] + $query))->getContent())), true);
        $this->assertTrue($config($second, ['write' => 1])['write']);
        $this->assertFalse($config($second)['write']);
        PageGeneration::create(['workspace_id' => $workspace->id, 'page_id' => $second->id, 'kind' => 'page', 'status' => 'completed', 'base_revision' => 1]);
        $this->assertFalse($config($second, ['write' => 1])['write'], 'Reloading after the write does not write again.');
        $this->assertSame(1, $config($second)['ai']['pageCost'], 'The next whole-page write costs a credit.');
    }

    public function test_an_older_page_turns_into_a_document_the_first_time_it_is_opened(): void
    {
        [$owner, $workspace, $product, $pack] = $this->fixture();
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();
        $library->approve($positioning->latestVersion, $owner);
        $page = $product->pages()->create(['workspace_id' => $workspace->id, 'name' => 'Old page', 'slug' => 'old', 'headline' => 'The old headline', 'draft_version' => 1]);
        $page->sections()->create(['position' => 1, 'kind' => 'hero', 'title' => 'Hero']);
        $page->sections()->create(['position' => 2, 'kind' => 'message', 'title' => 'Message', 'content_item_id' => $positioning->id]);
        $page->sections()->create(['position' => 3, 'kind' => 'cta', 'title' => 'Call to action', 'settings' => ['label' => 'Get yours', 'url' => 'https://example.com/buy', 'text' => '']]);
        $this->assertSame([], app(PageBuilder::class)->blockers($page), 'Older pages still publish as they are.');

        $this->actingAs($owner)->get(route('pages.edit', [$product, $page]))->assertOk();

        $page->refresh();
        $this->assertTrue($page->hasDocument());
        $this->assertSame(['studio', 1], [$page->template, $page->document_revision]);
        $this->assertSame(['product_hero', 'rich_text', 'cta'], array_column($page->document['sections'], 'type'));
        $this->assertSame('The old headline', $page->document['sections'][0]['settings']['title']);
        $this->assertSame((string) $positioning->latestVersion->body['brand_position'], $page->document['sections'][1]['settings']['text']);
        $this->assertSame(['Get yours', 'https://example.com/buy'], [$page->document['sections'][2]['settings']['button_label'], $page->document['sections'][2]['settings']['button_url']]);
        $this->assertSame(0, $page->sections()->count());
        $this->assertSame(1, $page->draft_version, 'Converting is not a new version: nothing on the page changed.');
    }

    private function editor(User $user, Page $page): Testable
    {
        $page->loadMissing('product');

        return Livewire::actingAs($user)->test(PageEditor::class, ['product' => $page->product, 'page' => $page]);
    }

    private function returned(Testable $component): mixed
    {
        return $component->effects['returns'][0] ?? null;
    }

    /** @return array{0: User, 1: Workspace, 2: Product, 3: CampaignPack} */
    private function fixture(string $name = 'Desk Lamp', ?Workspace $workspace = null): array
    {
        $owner = User::factory()->create();
        $workspace ??= Workspace::create(['name' => "{$name} Agency"]);
        $workspace->users()->syncWithoutDetaching([$owner->id => ['role' => 'owner']]);
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => $name, 'summary' => 'A warm reading lamp for small desks.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/lamp', 'content_hash' => hash('sha256', $name), 'status' => 'ready',
            'extracted_content' => 'A warm reading lamp for small desks.', 'extracted_truth' => ['name' => $name, 'description' => 'A warm reading lamp for small desks.'],
            'approved_by_user_id' => $owner->id, 'approved_at' => now(),
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => "{$name} Pack", 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);
        $product->facts()->updateOrCreate(['key' => 'availability'], ['workspace_id' => $workspace->id, 'confirmed_value' => 'In stock', 'status' => 'ok']);

        return [$owner, $workspace, $product, $pack];
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 218, 173, 115));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
