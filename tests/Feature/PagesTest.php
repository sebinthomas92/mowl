<?php

namespace Tests\Feature;

use App\Livewire\ProductPages;
use App\Livewire\StoreConnections;
use App\Models\CampaignPack;
use App\Models\Page;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PageActivity;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PagePublisher;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\PublicAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal WordPress with WooCommerce: pages and media in memory, served at their links. */
    private array $wp = ['pages' => [], 'media' => 0, 'stale' => false, 'fail' => null, 'requests' => []];

    public function test_a_new_draft_binds_approved_content_and_unapproved_content_blocks_publishing(): void
    {
        [$owner, , $pack] = $this->fixture();
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();
        $offers = $pack->contentItems()->where('section', 'offers')->firstOrFail();
        $library->approve($positioning->latestVersion, $owner);

        $page = app(PageBuilder::class)->start($pack->product, $owner);
        $sections = $page->sections()->get()->keyBy('kind');

        $this->assertSame(['hero', 'message', 'offer', 'benefits', 'specs', 'cta'], $page->sections()->pluck('kind')->all());
        $this->assertSame($positioning->id, $sections['message']->content_item_id);
        $this->assertNull($sections['offer']->content_item_id, 'Drafts start with approved content only.');
        $this->assertSame('https://example.com/pages', $sections['cta']->settings['url']);
        $this->assertSame([], app(PageBuilder::class)->blockers($page));

        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $pack->product_id])
            ->call('bind', $sections['offer']->id, (string) $offers->id)
            ->assertSee('Blocks publishing until approved or unbound.')
            ->assertSee('1 blocker');

        $this->expectException(ValidationException::class);
        app(PagePublisher::class)->publish($page, $owner);
    }

    public function test_publishing_to_woocommerce_sends_the_page_then_verifies_the_address_before_calling_it_live(): void
    {
        Notification::fake();
        [$owner, $workspace, $pack] = $this->fixture();
        $teammate = User::factory()->create();
        $workspace->users()->attach($teammate, ['role' => 'member']);
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);

        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $pack->product_id])
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSee('v1 is live');

        $page->refresh();
        $this->assertSame('live', $page->publish_state);
        $this->assertSame(1, $page->live_version);
        $this->assertSame('https://shop.test/pages-launch/', $page->remote_url);
        $sent = collect($this->wp['requests'])->first(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/wp-json/wp/v2/pages'));
        $this->assertSame('publish', $sent['status']);
        $this->assertStringContainsString('data-mowl-page="'.$page->id.'" data-mowl-version="1"', $sent['content']);
        $this->assertStringContainsString('Product details', $sent['content'], 'Confirmed facts fill the specs table.');
        $this->assertStringNotContainsString("\n", $sent['content'], 'WordPress would turn line breaks into paragraphs.');
        $this->assertTrue($sent->hasHeader('Authorization', 'Basic '.base64_encode('editor:abcdabcdabcdabcd')));
        $this->assertSame('live', $page->attempts()->sole()->status);
        $this->assertSame(1, $page->versions()->count());
        Notification::assertSentTo($teammate, PageActivity::class, fn (PageActivity $notification) => $notification->event === 'published');
    }

    public function test_a_publish_stays_pending_until_the_address_serves_it_and_fails_after_the_last_check(): void
    {
        config(['pages.verify_checks' => 3]);
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);
        $this->wp['stale'] = true;
        $publisher = app(PagePublisher::class);

        $attempt = $publisher->publish($page, $owner);
        $this->assertSame('verifying', $attempt->status);
        $this->assertSame('verifying', $page->fresh()->publish_state);
        $this->assertNull($page->fresh()->live_version);

        $publisher->verify($page->fresh());
        $this->assertSame('verifying', $page->fresh()->publish_state);
        $publisher->verify($page->fresh());

        $page->refresh();
        $this->assertSame('failed', $page->publish_state);
        $this->assertNull($page->live_version);
        $this->assertStringContainsString('still does not serve it after 3 checks', $page->last_error);
        $this->assertSame('unverified', $page->attempts()->sole()->status);

        $this->wp['stale'] = false;
        $this->assertSame('live', $publisher->publish($page->fresh(), $owner)->status, 'Retrying publishes the same frozen version.');
        $this->assertSame(1, $page->versions()->count());
    }

    public function test_a_rejected_publish_keeps_the_live_page_and_flags_the_connection(): void
    {
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $store = $this->connectStore($owner);
        $page->update(['store_connection_id' => $store->id]);
        $publisher = app(PagePublisher::class);
        $publisher->publish($page, $owner);

        app(PageBuilder::class)->edit($page->fresh(), fn (Page $locked) => $locked->update(['headline' => 'A sharper headline']));
        $this->wp['fail'] = 401;
        $attempt = $publisher->publish($page->fresh(), $owner);

        $page->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('failed', $page->publish_state);
        $this->assertSame(1, $page->live_version);
        $this->assertSame(2, $page->draft_version);
        $this->assertStringContainsString('The live page is unchanged (v1)', $page->last_error);
        $this->assertSame('needs_attention', $store->fresh()->status);
        $this->assertSame('1', $this->servedVersion(1), 'The store still serves v1.');

        $this->expectException(ValidationException::class);
        $publisher->publish($page, $owner);
    }

    public function test_editing_a_live_page_starts_a_new_draft_and_rollback_restores_the_earlier_version(): void
    {
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);
        $publisher = app(PagePublisher::class);
        $builder = app(PageBuilder::class);
        $publisher->publish($page, $owner);

        $builder->edit($page->fresh(), fn (Page $locked) => $locked->update(['headline' => 'Version two headline']));
        $builder->edit($page->fresh(), fn (Page $locked) => $locked->update(['headline' => 'Version two, edited again']));
        $this->assertSame(2, $page->fresh()->draft_version, 'Only the first edit after a publish starts a new version.');

        $this->assertSame('live', $publisher->publish($page->fresh(), $owner)->status);
        $this->assertCount(1, $this->wp['pages'], 'The second publish updates the same store page.');
        $this->assertStringContainsString('Version two, edited again', $this->wp['pages'][1]['content']);
        $this->assertSame('2', $this->servedVersion(1));

        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $pack->product_id])
            ->assertSee('Roll back live to v1')
            ->call('rollback', 1)
            ->assertHasNoErrors()
            ->assertSee('The live page is back on v1');

        $page->refresh();
        $this->assertSame(1, $page->live_version);
        $this->assertSame(2, $page->draft_version);
        $this->assertSame('1', $this->servedVersion(1));
        $this->assertStringNotContainsString('Version two', $this->wp['pages'][1]['content']);
        $this->assertSame(['publish', 'publish', 'rollback'], $page->attempts()->orderBy('id')->pluck('action')->all());
    }

    public function test_hero_images_upload_to_the_store_once(): void
    {
        [$owner, , $pack] = $this->fixture();
        $asset = $pack->product->mediaAssets()->create([
            'workspace_id' => $pack->product->brand->workspace_id, 'type' => 'image', 'disk' => 'local', 'path' => 'tests/hero.png',
            'original_name' => 'hero.png', 'mime_type' => 'image/png', 'size_bytes' => 4, 'content_hash' => 'x', 'status' => 'processed',
        ]);
        Storage::disk('local')->put('tests/hero.png', 'PNG!');
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);
        $publisher = app(PagePublisher::class);

        $publisher->publish($page, $owner);
        app(PageBuilder::class)->edit($page->fresh(), fn (Page $locked) => $locked->update(['headline' => 'Again']));
        $publisher->publish($page->fresh(), $owner);

        $this->assertSame(1, $this->wp['media']);
        $this->assertStringContainsString('src="https://shop.test/wp-content/uploads/media-1.png"', $this->wp['pages'][1]['content']);
        $this->assertSame("media-{$asset->id}", array_key_first($page->storeConnection->fresh()->media));
    }

    public function test_the_sandbox_store_runs_the_whole_flow_and_serves_the_page(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $this->approvedPage($owner, $pack);

        Livewire::actingAs($owner)->test(StoreConnections::class)
            ->call('open')
            ->set('driver', 'sandbox')
            ->set('name', 'Try-out store')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Try-out store is connected.');
        $store = StoreConnection::query()->sole();

        Livewire::actingAs($member)->test(ProductPages::class, ['productId' => $pack->product_id])
            ->assertSee('Only workspace owners can publish pages.')
            ->call('chooseStore', (string) $store->id)
            ->assertForbidden();

        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $pack->product_id])
            ->call('chooseStore', (string) $store->id)
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSee('v1 is live');

        $page = Page::query()->sole();
        $this->assertSame('live', $page->publish_state);
        $this->get($page->remote_url)->assertOk()->assertSee('data-mowl-version="1"', false)->assertSee('sandbox store')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('sandbox-store.home', $store->api_base))->assertOk()->assertSee($page->name);
        $this->get(route('sandbox-store.home', str_repeat('x', 40)))->assertNotFound();
        $this->expectException(ValidationException::class);
        app(PagePublisher::class)->publish($page, $member);
    }

    public function test_connecting_a_store_reports_what_is_wrong(): void
    {
        [$owner] = $this->fixture();
        $this->fakeWordPress();
        $form = fn () => Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')
            ->set('name', 'Shop')->set('siteUrl', 'https://shop.test')->set('username', 'editor');

        $form()->set('secret', 'wrong')->call('save')->assertHasErrors('secret')->assertSee('WordPress rejected the username or application password.');
        $form()->set('siteUrl', 'https://plain.test')->set('secret', 'abcd abcd abcd abcd')->call('save')->assertHasErrors('siteUrl')->assertSee('does not look like a WordPress site');
        $this->assertSame(0, StoreConnection::query()->count());

        $form()->set('secret', 'abcd abcd abcd abcd')->call('save')->assertHasNoErrors();
        $store = StoreConnection::query()->sole();
        $this->assertTrue($store->has_woocommerce);
        $this->assertSame('abcdabcdabcdabcd', $store->secret);
        $this->assertNotSame('abcdabcdabcdabcd', $store->getRawOriginal('secret'), 'Application passwords are stored encrypted.');
        $this->assertSame('https://shop.test/wp-json/', $store->api_base);
    }

    public function test_store_addresses_on_private_networks_are_refused(): void
    {
        config(['pages.allow_private_hosts' => false]);

        foreach (['http://127.0.0.1/', 'https://10.0.0.5', 'http://localhost', 'https://shop.example.com:8443', 'file:///etc/passwd'] as $url) {
            try {
                PublicAddress::binding($url);
                $this->fail("{$url} should be refused.");
            } catch (StoreException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_pages_belong_to_their_workspace(): void
    {
        [$owner, , $pack] = $this->fixture();
        [$stranger] = $this->fixture('Other');
        $page = app(PageBuilder::class)->start($pack->product, $owner);

        Livewire::actingAs($stranger)->test(ProductPages::class, ['productId' => $pack->product_id])->assertNotFound();
        $this->actingAs($owner)->get(route('products.show', [$pack->product, 'tab' => 'pages']))
            ->assertOk()->assertSee('Sections and content')->assertSee($page->name);
    }

    private function servedVersion(int $id): ?string
    {
        preg_match('/data-mowl-version="(\d+)"/', $this->wp['pages'][$id]['content'] ?? '', $match);

        return $match[1] ?? null;
    }

    private function fakeWordPress(): void
    {
        Http::fake(function (Request $request) {
            $this->wp['requests'][] = $request;
            $url = $request->url();
            $authed = $request->hasHeader('Authorization', 'Basic '.base64_encode('editor:abcdabcdabcdabcd'));

            if (str_starts_with($url, 'https://plain.test')) {
                return Http::response('<html>Not WordPress</html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($url === 'https://shop.test/wp-json/') {
                return Http::response(['name' => 'Shop', 'namespaces' => ['oembed/1.0', 'wp/v2', 'wc/v3']]);
            }
            if (str_starts_with($url, 'https://shop.test/wp-json/wp/v2/users/me')) {
                return $authed
                    ? Http::response(['id' => 2, 'capabilities' => ['edit_pages' => true, 'publish_pages' => true, 'upload_files' => true]])
                    : Http::response(['code' => 'incorrect_password', 'message' => 'The provided password is an invalid application password.'], 401);
            }
            if ($this->wp['fail'] && str_contains($url, '/wp-json/')) {
                return Http::response(['code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.'], $this->wp['fail']);
            }
            if (! $authed && str_contains($url, '/wp-json/')) {
                return Http::response(['code' => 'rest_not_logged_in'], 401);
            }
            if ($url === 'https://shop.test/wp-json/wp/v2/media') {
                $id = ++$this->wp['media'];

                return Http::response(['id' => $id, 'source_url' => "https://shop.test/wp-content/uploads/media-{$id}.png"], 201);
            }
            if (preg_match('#^https://shop\.test/wp-json/wp/v2/pages(?:/(\d+))?$#', $url, $match) && $request->method() === 'POST') {
                $id = isset($match[1]) ? (int) $match[1] : count($this->wp['pages']) + 1;
                $previous = $this->wp['pages'][$id] ?? null;
                $this->wp['pages'][$id] = ['slug' => $request['slug'], 'content' => $request['content'], 'previous' => $previous['content'] ?? ''];

                return Http::response(['id' => $id, 'link' => 'https://shop.test/'.$request['slug'].'/'], isset($match[1]) ? 200 : 201);
            }
            foreach ($this->wp['pages'] as $stored) {
                if (str_starts_with($url, 'https://shop.test/'.$stored['slug'].'/')) {
                    return Http::response('<html><body>'.($this->wp['stale'] ? $stored['previous'] : $stored['content']).'</body></html>', 200, ['Content-Type' => 'text/html']);
                }
            }

            return Http::response('Not found', 404);
        });
    }

    private function connectStore(User $owner): StoreConnection
    {
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')
            ->set('name', 'Northline store')->set('siteUrl', 'https://shop.test')->set('username', 'editor')->set('secret', 'abcd abcd abcd abcd')
            ->call('save')->assertHasNoErrors();

        return StoreConnection::query()->latest('id')->firstOrFail();
    }

    /** A page draft whose bound content is all approved, plus a confirmed price for the specs table. */
    private function approvedPage(User $owner, CampaignPack $pack): Page
    {
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        foreach ($pack->contentItems()->whereIn('section', ['positioning', 'offers', 'ranked_angles'])->get() as $item) {
            $library->approve($item->latestVersion, $owner);
        }
        $pack->product->facts()->updateOrCreate(['key' => 'availability'], [
            'workspace_id' => $pack->product->brand->workspace_id, 'confirmed_value' => 'In stock', 'status' => 'ok',
        ]);
        $page = app(PageBuilder::class)->start($pack->product, $owner);
        $page->update(['slug' => 'pages-launch']);

        return $page->fresh();
    }

    private function fixture(string $name = 'Pages'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => "{$name} Agency"]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => "{$name} Brand"])->products()->create(['name' => "{$name} Product", 'summary' => 'A directly supported page product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/'.strtolower($name),
            'content_hash' => hash('sha256', $name),
            'status' => 'ready',
            'extracted_content' => 'A directly supported page product fact.',
            'extracted_truth' => ['description' => 'A directly supported page product fact.'],
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => "{$name} Pack", 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $workspace, $pack];
    }
}
