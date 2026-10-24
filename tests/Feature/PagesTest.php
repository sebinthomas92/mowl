<?php

namespace Tests\Feature;

use App\Livewire\PageEditor;
use App\Livewire\ProductPages;
use App\Livewire\StoreConnections;
use App\Models\BannerCreative;
use App\Models\CampaignPack;
use App\Models\ContentItem;
use App\Models\Page;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PageActivity;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PagePublisher;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\PublicAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal WordPress with WooCommerce: pages and media in memory, served at their links. */
    private array $wp = ['pages' => [], 'media' => 0, 'stale' => false, 'fail' => null, 'timeout' => false, 'filtered' => false, 'templates' => ['page-no-title'], 'link' => null, 'requests' => []];

    public function test_a_new_page_is_filled_from_the_product_and_claims_its_sources_do_not_back_hold_publishing(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $page = $this->approvedPage($owner, $pack);
        $builder = app(PageBuilder::class);
        $types = array_column($page->document['sections'], 'type');

        $this->assertSame('studio', $page->template);
        $this->assertSame(1, $page->document_revision);
        $this->assertContains('product_hero', $types);
        $this->assertContains('cta', $types);
        $this->assertNotContains('comparison', $types, 'With no confirmed differences the comparison is left out, not invented.');
        $this->assertSame('Pages Product', $page->document['sections'][$this->index($page, 'product_hero')]['settings']['title']);
        $this->assertSame('https://example.com/pages', $page->document['buy_url'], 'Buttons go to the product page.');
        $this->assertSame([], $builder->blockers($page), 'Text taken from the sources needs no confirmation.');

        $hero = $this->index($page, 'product_hero');
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.subtitle", 'Clinically proven to last 10 years'));
        $issue = collect($builder->issues($page))->firstWhere('kind', 'confirm');
        $this->assertSame('Product hero · Subtitle', $issue['where']);
        $this->assertStringContainsString('“clinically”', $issue['message']);
        $this->assertStringContainsString('10', $issue['message']);
        $this->assertSame(["Product hero · Subtitle needs an owner's confirmation before publishing."], $builder->blockers($page));
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);
        try {
            app(PagePublisher::class)->publish($page->fresh(), $owner);
            $this->fail('An unconfirmed claim holds publishing.');
        } catch (ValidationException $exception) {
            $this->assertSame("Product hero · Subtitle needs an owner's confirmation before publishing.", $exception->errors()['publish'][0]);
        }

        try {
            $builder->confirm($page, $issue['key'], $member);
            $this->fail('Only owners confirm claims.');
        } catch (ValidationException $exception) {
            $this->assertSame('Only workspace owners can confirm what a page claims.', $exception->errors()['confirm'][0]);
        }
        $builder->confirm($page->fresh(), $issue['key'], $owner);
        $page->refresh();
        $this->assertSame([], $builder->blockers($page));
        $this->assertSame($owner->name, collect($builder->issues($page))->firstWhere('key', $issue['key'])['confirmed']['name']);
        $this->assertSame('page_claim_confirmed', $workspace->auditEvents()->latest('id')->value('event'));

        // The browser cannot write confirmations, and a confirmation lapses when the wording changes.
        $forged = $page->document;
        $forged['confirmations'] = [sha1('anything') => ['by' => $member->id, 'name' => 'Someone']];
        $builder->saveDocument($page, $forged, $page->document_revision);
        $this->assertSame([$issue['key']], array_keys($page->fresh()->document['confirmations']));
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.subtitle", 'Clinically proven to last 12 years'));
        $this->assertCount(1, $builder->blockers($page));

        // Proof sections always need an owner, and a price that differs from the product's blocks outright.
        $page = $this->editDocument($page, function (array $document) use ($hero): array {
            $document['sections'][$hero]['settings']['subtitle'] = 'A lamp for reading';
            $document['sections'][] = Document::section('reviews', ['items' => [['name' => 'Asha', 'rating' => 5, 'text' => 'I read every night with it.']]]);

            return $document;
        });
        $this->assertSame(["Customer reviews needs an owner's confirmation before publishing."], $builder->blockers($page));
        $pack->product->facts()->updateOrCreate(['key' => 'price'], ['workspace_id' => $workspace->id, 'confirmed_value' => '₹699', 'status' => 'ok']);
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.price", '₹599'));
        $this->assertContains("The page shows ₹599, but the product's price is ₹699. Show only the price your store charges (a former price goes in Compare-at price), or correct it in Knowledge.", $builder->blockers($page));
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.price", 'Rs. 699'));
        $this->assertSame(["Customer reviews needs an owner's confirmation before publishing."], $builder->blockers($page), 'The same amount written another way matches.');
    }

    public function test_publishing_to_woocommerce_sends_the_page_then_verifies_the_address_before_calling_it_live(): void
    {
        Notification::fake();
        [$owner, $workspace, $pack] = $this->fixture();
        $teammate = User::factory()->create();
        $workspace->users()->attach($teammate, ['role' => 'member']);
        $otherBrand = $workspace->brands()->create(['name' => 'Other Brand']);
        $scopedElsewhere = User::factory()->create();
        $workspace->users()->attach($scopedElsewhere, ['role' => 'reviewer', 'brand_id' => $otherBrand->id]);
        $client = User::factory()->create();
        $workspace->users()->attach($client, ['role' => 'client', 'brand_id' => $pack->product->brand_id]);
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);

        $this->editor($owner, $page)
            ->call('openDrawer')
            ->assertSee("Everything the page claims is backed by the product's sources.", false)
            ->call('publish', $page->fresh()->document_revision)
            ->assertHasNoErrors()
            ->assertSee('v1 is live')
            ->assertDispatched('page-state');

        $page->refresh();
        $this->assertSame('live', $page->publish_state);
        $this->assertSame(1, $page->live_version);
        $this->assertSame('https://shop.test/pages-launch/', $page->remote_url);
        $sent = collect($this->wp['requests'])->first(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/wp-json/wp/v2/pages'));
        $this->assertSame('publish', $sent['status']);
        $this->assertSame('page-no-title', $sent['template'], 'A new page asks for the theme template without the page title.');
        $this->assertStringStartsWith('<!-- wp:html --><div class="mowl-page alignfull" data-mowl-page="'.$page->id.'" data-mowl-version="1"', $sent['content']);
        $this->assertStringContainsString('Pages Product', $sent['content']);
        $this->assertStringContainsString('href="https://example.com/pages"', $sent['content'], 'Buttons link to the buy link.');
        $this->assertStringNotContainsString('data-mowl-section', $sent['content'], 'Editing hooks stay in the editor.');
        $this->assertStringNotContainsString("\n", $sent['content'], 'WordPress would turn line breaks into paragraphs.');
        $this->assertTrue($sent->hasHeader('Authorization', 'Basic '.base64_encode('editor:abcdabcdabcdabcd')));
        $this->assertSame('live', $page->attempts()->sole()->status);
        $this->assertNull($page->attempts()->sole()->warning);
        $this->assertSame(1, $page->versions()->count());
        $this->assertSame($page->document['sections'], $page->versions()->sole()->snapshot['document']['sections'], 'The version keeps the document as it was published.');
        Notification::assertSentTo($teammate, PageActivity::class, fn (PageActivity $notification) => $notification->event === 'published');
        Notification::assertNotSentTo([$scopedElsewhere, $client], PageActivity::class);
    }

    public function test_a_store_that_strips_layout_styles_is_reported(): void
    {
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $this->wp['filtered'] = true;
        $this->wp['templates'] = [];
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);

        $this->editor($owner, $page)->call('openDrawer')->call('publish', $page->fresh()->document_revision)->assertHasNoErrors()
            ->assertSee('removed its layout styles');
        $this->assertStringContainsString('Administrator account', (string) $page->attempts()->sole()->warning);
        $creates = collect($this->wp['requests'])->filter(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/wp-json/wp/v2/pages'))->values();
        $this->assertCount(2, $creates, 'A theme without the title-less template gets the page with its default template.');
        $this->assertArrayNotHasKey('template', $creates[1]->data());
        $this->assertCount(1, $this->wp['pages']);
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

        $this->editor($owner, $page)->call('verifyPending')->assertDispatched('page-state');
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

        $hero = $this->index($page, 'product_hero');
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.title", 'A sharper title'));
        $this->wp['fail'] = 401;
        $attempt = $publisher->publish($page, $owner);

        $page->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('failed', $page->publish_state);
        $this->assertSame(1, $page->live_version);
        $this->assertSame(2, $page->draft_version);
        $this->assertStringContainsString('The live page is unchanged (v1)', $page->last_error);
        $this->assertSame('needs_attention', $store->fresh()->status);
        $this->assertSame('1', $this->servedVersion(1), 'The store still serves v1.');
        $this->editor($owner, $page)->call('openDrawer')->assertSee('The live page is unchanged (v1)')->assertSee('Retry publishing v2');

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
        $publisher->publish($page, $owner);
        $hero = $this->index($page, 'product_hero');

        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.title", 'Version two title'));
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.title", 'Version two, edited again'));
        $this->assertSame(2, $page->draft_version, 'Only the first edit after a publish starts a new version.');
        $this->assertSame(3, $page->document_revision);
        $unchanged = app(PageBuilder::class)->saveDocument($page, $page->document, $page->document_revision);
        $this->assertFalse($unchanged['changed']);
        $this->assertSame(3, $page->fresh()->document_revision, 'Saving the same document is not a change.');

        $this->assertSame('live', $publisher->publish($page->fresh(), $owner)->status);
        $this->assertCount(1, $this->wp['pages'], 'The second publish updates the same store page.');
        $update = collect($this->wp['requests'])->last(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/wp-json/wp/v2/pages/1'));
        $this->assertArrayNotHasKey('template', $update->data(), 'A template chosen in WordPress stays.');
        $this->assertStringContainsString('Version two, edited again', $this->wp['pages'][1]['content']);
        $this->assertSame('2', $this->servedVersion(1));

        $this->editor($owner, $page)
            ->call('openDrawer')
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
        $this->assertSame('Version two, edited again', $page->document['sections'][$hero]['settings']['title'], 'The draft is kept.');
    }

    public function test_images_upload_to_the_store_once_with_their_size(): void
    {
        [$owner, , $pack] = $this->fixture();
        $asset = $pack->product->mediaAssets()->create([
            'workspace_id' => $pack->product->brand->workspace_id, 'type' => 'image', 'disk' => 'local', 'path' => 'tests/hero.png',
            'original_name' => 'hero.png', 'mime_type' => 'image/png', 'size_bytes' => 4, 'content_hash' => 'x', 'status' => 'processed',
            'metadata' => ['origin' => 'product_page', 'width' => 1200, 'height' => 900],
        ]);
        Storage::disk('local')->put('tests/hero.png', 'PNG!');
        $page = $this->approvedPage($owner, $pack);
        $this->assertSame("media-{$asset->id}", $page->document['sections'][$this->index($page, 'product_hero')]['settings']['images'][0]['key'], 'The hero starts with the product photo.');
        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);
        $publisher = app(PagePublisher::class);

        $publisher->publish($page, $owner);
        $hero = $this->index($page, 'product_hero');
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.title", 'Again'));
        $publisher->publish($page, $owner);

        $this->assertSame(1, $this->wp['media'], 'Every section using the photo shares one upload, across publishes.');
        $this->assertStringContainsString('src="https://shop.test/wp-content/uploads/media-1.png" alt="Pages Product" width="1200" height="900"', $this->wp['pages'][1]['content']);
        $this->assertStringContainsString('class="wp-image-1"', $this->wp['pages'][1]['content'], 'WordPress serves sized copies of its own uploads.');
        $this->assertSame("media-{$asset->id}", array_key_first($page->storeConnection->fresh()->media));
    }

    public function test_the_sandbox_store_runs_the_whole_flow_and_serves_the_page(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $page = $this->approvedPage($owner, $pack);

        Livewire::actingAs($owner)->test(StoreConnections::class)
            ->call('open')
            ->set('driver', 'sandbox')
            ->set('name', 'Try-out store')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Try-out store is connected.');
        $store = StoreConnection::query()->sole();

        $this->editor($member, $page)
            ->call('openDrawer')
            ->assertSee('Only workspace owners can publish pages.')
            ->call('chooseStore', (string) $store->id)
            ->assertForbidden();

        $this->editor($owner, $page)
            ->call('chooseStore', (string) $store->id)
            ->call('openDrawer')
            ->call('publish', $page->fresh()->document_revision)
            ->assertHasNoErrors()
            ->assertSee('v1 is live');

        $page->refresh();
        $this->assertSame('live', $page->publish_state);
        $this->get($page->remote_url)->assertOk()->assertSee('data-mowl-version="1"', false)->assertSee('<main class="full">', false)
            ->assertSee('sandbox store')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('sandbox-store.home', $store->api_base))->assertOk()->assertSee($page->name);
        $this->get(route('sandbox-store.home', str_repeat('x', 40)))->assertNotFound();
        $this->expectException(ValidationException::class);
        app(PagePublisher::class)->publish($page, $member);
    }

    public function test_owners_connect_a_store_by_approving_marketingowl_in_wordpress(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->fakeWordPress();
        $approve = Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')
            ->set('name', 'Northline store')->set('siteUrl', 'https://shop.test/')
            ->call('connectWithWordPress')->assertHasNoErrors();

        $target = $approve->effects['redirect'];
        $this->assertStringStartsWith('https://shop.test/wp-admin/authorize-application.php?', $target);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame('MarketingOwl', $query['app_name']);
        $this->assertSame(config('pages.wordpress_app_id'), $query['app_id']);
        $this->assertStringStartsWith(route('stores.wordpress.return').'?state=', $query['success_url']);
        $this->assertSame(0, StoreConnection::query()->count(), 'Nothing is saved until WordPress approves.');

        // Declining in WordPress, a forged state, or someone else's link connect nothing.
        $this->assertStringEndsWith('&success=false', $query['reject_url']);
        $this->actingAs($owner)->get($query['reject_url'])->assertRedirect(route('workspace.settings').'#connections')
            ->assertSessionHas('stores.problem', fn ($problem) => str_contains($problem, 'not approved'));
        $this->actingAs($owner)->get(route('stores.wordpress.return', ['state' => 'forged', 'user_login' => 'editor', 'password' => 'abcd']))
            ->assertSessionHas('stores.problem', fn ($problem) => str_contains($problem, 'expired'));
        $stranger = User::factory()->create();
        $workspace->users()->attach($stranger, ['role' => 'owner']);
        $this->actingAs($stranger)->get($query['success_url'].'&user_login=editor&password=abcd+abcd+abcd+abcd')->assertSessionHas('stores.problem');
        $this->assertSame(0, StoreConnection::query()->count());

        // Approving brings the owner back connected, with the password WordPress made.
        $this->actingAs($owner)->get($query['success_url'].'&site_url=https%3A%2F%2Fshop.test&user_login=editor&password=abcd+abcd+abcd+abcd')
            ->assertRedirect(route('workspace.settings').'#connections')
            ->assertSessionHas('stores.flash', 'Northline store is connected. You can publish pages to it now.');
        $store = StoreConnection::query()->sole();
        $this->assertSame(['https://shop.test', 'editor', 'abcdabcdabcdabcd', 'connected'], [$store->site_url, $store->username, $store->secret, $store->status]);
        $this->assertSame('store_connection_added', $workspace->auditEvents()->latest('id')->value('event'));
        $this->actingAs($owner)->get($query['success_url'].'&user_login=editor&password=abcd+abcd+abcd+abcd')
            ->assertSessionHas('stores.problem', fn ($problem) => str_contains($problem, 'already used'));
        $this->assertSame(1, StoreConnection::query()->count(), 'Replaying the return trip adds nothing.');

        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')
            ->set('name', 'Locked')->set('siteUrl', 'https://locked.test')
            ->call('connectWithWordPress')->assertHasErrors('siteUrl')->assertSee('application passwords switched off');
    }

    public function test_connecting_a_store_reports_what_is_wrong(): void
    {
        [$owner] = $this->fixture();
        $this->fakeWordPress();
        $form = fn () => Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')
            ->set('name', 'Shop')->set('siteUrl', 'https://shop.test')->set('username', 'editor');

        $form()->set('secret', 'wrong')->call('save')->assertHasErrors('secret')->assertSee('WordPress rejected the username or application password.');
        $form()->set('siteUrl', 'https://plain.test')->set('secret', 'abcd abcd abcd abcd')->call('save')->assertHasErrors('siteUrl')->assertSee('does not look like a WordPress site');
        $form()->set('siteUrl', 'https://locked.test')->set('secret', 'abcd abcd abcd abcd')->call('save')->assertHasErrors('secret')->assertSee('application passwords switched off');
        $this->assertSame(0, StoreConnection::query()->count());

        $form()->assertSee('wp-admin/profile.php#application-passwords-section', false);
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

    public function test_a_page_moved_to_another_store_never_reuses_its_old_page_there(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $first = $this->connectStore($owner);
        $page->update(['store_connection_id' => $first->id]);
        app(PagePublisher::class)->publish($page, $owner);
        $this->assertSame($first->remoteKey(), $page->fresh()->remote_store);

        $second = $this->connectStore($owner);
        $this->wp['stale'] = true;
        $this->editor($owner, $page)
            ->call('chooseStore', (string) $second->id)
            ->call('publish', $page->fresh()->document_revision)
            ->assertHasNoErrors();
        $this->assertCount(2, $this->wp['pages'], 'The second store gets its own page, not an update of the first one.');
        $this->assertSame('verifying', $page->fresh()->publish_state);

        $this->editor($owner, $page)
            ->call('chooseStore', (string) $first->id)
            ->assertHasErrors('publish');
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('remove', $second->id);
        $page->refresh();
        $this->assertSame('failed', $page->publish_state);
        $this->assertStringContainsString('was removed while this page was publishing', $page->last_error);
        $this->assertSame(['live', 'failed'], $page->attempts()->orderBy('id')->pluck('status')->all());

        $otherBrand = $workspace->brands()->create(['name' => 'Other Brand']);
        $foreign = StoreConnection::create(['workspace_id' => $workspace->id, 'brand_id' => $otherBrand->id, 'name' => 'Other store', 'driver' => 'sandbox', 'site_url' => 'https://other.test', 'status' => 'connected']);
        $this->editor($owner, $page)->call('chooseStore', (string) $foreign->id)->assertNotFound();
        $page->update(['store_connection_id' => $foreign->id]);
        $this->expectException(ValidationException::class);
        app(PagePublisher::class)->publish($page->fresh(), $owner);
    }

    public function test_a_lost_answer_is_not_reported_as_unchanged_and_a_new_store_address_starts_over(): void
    {
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $store = $this->connectStore($owner);
        $page->update(['store_connection_id' => $store->id]);
        $publisher = app(PagePublisher::class);
        $publisher->publish($page, $owner);
        $this->assertSame('live', $page->fresh()->publish_state);

        $this->wp['timeout'] = true;
        $cta = $this->index($page, 'cta');
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$cta}.settings.heading", 'Ready when you are'));
        $this->assertSame(2, $page->draft_version);
        $publisher->publish($page, $owner);
        $page->refresh();
        $this->assertSame('failed', $page->publish_state);
        $this->assertStringContainsString('may have saved the page anyway', $page->last_error);
        $this->assertStringNotContainsString('unchanged', $page->last_error);
        $this->wp['timeout'] = false;

        $store->update(['media' => ['media-1' => ['id' => 1, 'url' => 'https://shop.test/old.png']]]);
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open', $store->id)
            ->set('siteUrl', 'https://moved.test')->call('save')->assertHasNoErrors();
        $page->refresh();
        $this->assertNull($store->fresh()->media);
        $this->assertSame('idle', $page->publish_state);
        $this->assertNull($page->live_version);
        $this->assertNull($page->remote_id);

        $before = count($this->wp['pages']);
        $this->assertSame('live', $publisher->publish($page, $owner)->status);
        $this->assertCount($before + 1, $this->wp['pages'], 'The new address gets a new page.');
    }

    public function test_pages_belong_to_their_workspace(): void
    {
        [$owner, , $pack] = $this->fixture();
        [$stranger] = $this->fixture('Other');
        $page = app(PageBuilder::class)->start($pack->product, $owner);

        Livewire::actingAs($stranger)->test(ProductPages::class, ['productId' => $pack->product_id])->assertNotFound();
        $this->actingAs($stranger)->get(route('pages.edit', [$pack->product, $page]))->assertNotFound();
        $this->actingAs($stranger)->get(route('pages.preview', [$pack->product, $page]))->assertNotFound();
        $this->actingAs($stranger)->get(route('pages.template-preview', [$pack->product, 'studio']))->assertNotFound();
        $this->actingAs($owner)->get(route('products.show', [$pack->product, 'tab' => 'pages']))
            ->assertOk()->assertSee('Landing pages')->assertSee($page->name)->assertSee('Open editor');
    }

    public function test_a_page_uses_a_banner_only_once_it_is_approved_and_shows_no_price_the_product_page_does_not(): void
    {
        Storage::fake('local');
        [$owner, , $pack] = $this->fixture();
        $draft = $this->banner($pack, $owner, 'Read in comfort');
        $approved = $this->banner($pack, $owner, 'Read in comfort, all night', approved: true);
        // Approved before its price stopped matching the product page.
        $priced = $this->banner($pack, $owner, 'INR 399 today only', approved: true);
        $page = $this->approvedPage($owner, $pack);
        $builder = app(PageBuilder::class);

        $keys = array_column(ProductBrief::images($pack->product), 'key');
        $this->assertContains("banner-{$approved->id}", $keys);
        $this->assertNotContains("banner-{$draft->id}", $keys, 'The image picker leaves out banners not approved yet.');
        $this->assertNotContains("banner-{$priced->id}", $keys);
        $this->editor($owner, $page)->assertSee('"withheldBanners":2', false);

        $hero = $this->index($page, 'product_hero');
        $use = fn (int $id) => $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.images", [['kind' => 'banner', 'id' => $id, 'alt' => 'Banner']]));
        $page = $use($draft->id);
        $this->assertSame("banner-{$draft->id}", $page->document['sections'][$hero]['settings']['images'][0]['key'], 'The page keeps the banner so it can say why it cannot go out.');
        $this->assertSame(['The banner “Read in comfort” is not approved yet. Approve it in Review, or choose another image.'], $builder->blockers($page));
        $page = $use($priced->id);
        $this->assertSame(['The banner “INR 399 today only” shows a price the product page does not (399). Choose another image.'], $builder->blockers($page));
        $page = $use($approved->id);
        $this->assertSame([], $builder->blockers($page));

        $this->fakeWordPress();
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);
        $this->assertSame('live', app(PagePublisher::class)->publish($page->fresh(), $owner)->status);
        $this->assertSame(1, $this->wp['media'], 'The approved banner is uploaded with the page.');
    }

    public function test_the_hero_price_is_the_products_price_in_its_currency_and_a_former_price_goes_in_compare_at(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $pack->product->facts()->updateOrCreate(['key' => 'price'], ['workspace_id' => $workspace->id, 'confirmed_value' => '₹699', 'status' => 'ok']);
        $page = $this->approvedPage($owner, $pack);
        $builder = app(PageBuilder::class);
        $hero = $this->index($page, 'product_hero');
        $price = function (string $price, string $compare = '') use (&$page, $hero, $builder): array {
            $page = $this->editDocument($page, function (array $document) use ($hero, $price, $compare): array {
                $document['sections'][$hero]['settings']['price'] = $price;
                $document['sections'][$hero]['settings']['compare_price'] = $compare;

                return $document;
            });

            return collect($builder->issues($page))->where('section', $page->document['sections'][$hero]['id'])->map(fn (array $issue) => [$issue['kind'], $issue['path']])->values()->all();
        };

        foreach (['₹699', 'Rs. 699', 'INR 699.00', '699'] as $same) {
            $this->assertSame([], $price($same), "{$same} is the product's price.");
        }
        foreach (['$699', '699 USD', '₹599', '₹699 · was ₹1,999', 'Now ₹699 (was ₹999)'] as $wrong) {
            $this->assertContains(['blocker', 'price'], $price($wrong), "{$wrong} is not the product's price alone.");
        }
        $this->assertSame([['confirm', 'price']], $price('₹699, 65% off today'), 'Words beside the price are claims to confirm.');
        $this->assertSame([['blocker', 'compare_price']], $price('₹699', '$1,999'), 'A former price in another currency would make the saving wrong.');
        $this->assertContains(['blocker', 'compare_price'], $price('₹699', '₹1,999 ₹2,499'));
        $this->assertSame([['confirm', 'compare_price']], $price('₹699', '₹1,999'), 'A former price the product page does not show needs an owner.');
    }

    public function test_a_guarantee_heading_on_its_own_still_needs_an_owner(): void
    {
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $page = $this->editDocument($page, function (array $document): array {
            $document['sections'][] = Document::section('guarantee', ['heading' => '30-day money-back guarantee, no questions asked', 'text' => '']);

            return $document;
        });

        $issue = collect(app(PageBuilder::class)->issues($page))->firstWhere('where', 'Guarantee');
        $this->assertSame('confirm', $issue['kind']);
        $this->assertSame('30-day money-back guarantee, no questions asked', $issue['text']);
        $this->assertSame(["Guarantee needs an owner's confirmation before publishing."], app(PageBuilder::class)->blockers($page));
    }

    public function test_confirming_a_claim_works_when_old_confirmations_have_filled_the_limit(): void
    {
        [$owner, , $pack] = $this->fixture();
        $builder = app(PageBuilder::class);
        $page = $this->approvedPage($owner, $pack);
        $hero = $this->index($page, 'product_hero');
        $page = $this->editDocument($page, fn (array $document) => data_set($document, "sections.{$hero}.settings.subtitle", 'Loved by 12,000 readers'));
        $document = $page->document;
        for ($i = 0; $i < Document::MAX_CONFIRMATIONS; $i++) {
            $document['confirmations'][sha1("old wording {$i}")] = ['by' => $owner->id, 'name' => $owner->name, 'at' => now()->toIso8601String(), 'text' => 'Old wording'];
        }
        Page::query()->whereKey($page->id)->update(['document' => json_encode($document)]);
        $page->refresh();
        $key = collect($builder->issues($page))->firstWhere('kind', 'confirm')['key'];

        $builder->confirm($page, $key, $owner);

        $page->refresh();
        $this->assertSame([], $builder->blockers($page));
        $this->assertSame([$key], array_keys($page->document['confirmations']), 'Confirmations of wording the page no longer has are dropped at the limit.');
    }

    public function test_a_store_address_that_is_not_a_web_link_is_not_kept(): void
    {
        [$owner, , $pack] = $this->fixture();
        $page = $this->approvedPage($owner, $pack);
        $this->fakeWordPress();
        $this->wp['link'] = 'javascript:alert(document.domain)';
        $page->update(['store_connection_id' => $this->connectStore($owner)->id]);

        $attempt = app(PagePublisher::class)->publish($page->fresh(), $owner);

        $this->assertSame('failed', $attempt->status);
        $this->assertStringContainsString('gave an address that is not a web link', (string) $attempt->error);
        $this->assertNull($page->fresh()->remote_url);
    }

    private function editor(User $user, Page $page)
    {
        $page->loadMissing('product');

        return Livewire::actingAs($user)->test(PageEditor::class, ['product' => $page->product, 'page' => $page]);
    }

    /** Saves a change to the page document the way the editor does, from the current revision. */
    private function editDocument(Page $page, callable $change): Page
    {
        $page->refresh();
        app(PageBuilder::class)->saveDocument($page, $change($page->document), $page->document_revision);

        return $page->fresh();
    }

    private function index(Page $page, string $type): int
    {
        $index = array_search($type, array_column($page->document['sections'], 'type'), true);
        $this->assertIsInt($index, "The page has a {$type} section.");

        return $index;
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
            // moved.test is the same fake site under a new address.
            $url = preg_replace('#^https://moved\.test#', 'https://shop.test', $request->url());
            $authed = $request->hasHeader('Authorization', 'Basic '.base64_encode('editor:abcdabcdabcdabcd'));

            if (str_starts_with($url, 'https://plain.test')) {
                return Http::response('<html>Not WordPress</html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($url === 'https://locked.test/wp-json/') {
                return Http::response(['name' => 'Locked', 'namespaces' => ['wp/v2'], 'authentication' => []]);
            }
            if (str_starts_with($url, 'https://locked.test/')) {
                return Http::response(['code' => 'rest_not_logged_in'], 401);
            }
            if ($url === 'https://shop.test/wp-json/') {
                return Http::response(['name' => 'Shop', 'namespaces' => ['oembed/1.0', 'wp/v2', 'wc/v3'], 'authentication' => [
                    'application-passwords' => ['endpoints' => ['authorization' => 'https://shop.test/wp-admin/authorize-application.php']],
                ]]);
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
            if ($this->wp['timeout'] && str_contains($url, '/wp/v2/pages')) {
                return Http::failedConnection('Operation timed out after 20001 milliseconds');
            }
            if (preg_match('#^https://shop\.test/wp-json/wp/v2/pages(?:/(\d+))?$#', $url, $match) && $request->method() === 'POST') {
                // Like WordPress, a template the theme does not have is refused before anything is saved.
                if (filled($request['template'] ?? null) && ! in_array($request['template'], $this->wp['templates'], true)) {
                    return Http::response(['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): template', 'data' => [
                        'status' => 400, 'params' => ['template' => 'template is not one of '.implode(', ', $this->wp['templates']).'.'],
                    ]], 400);
                }
                $id = isset($match[1]) ? (int) $match[1] : count($this->wp['pages']) + 1;
                $previous = $this->wp['pages'][$id] ?? null;
                // An editor without unfiltered_html on WordPress before 7.0: "display" is filtered out.
                $content = $this->wp['filtered'] ? preg_replace('/display:[a-z-]+;?/', '', $request['content']) : $request['content'];
                $this->wp['pages'][$id] = ['slug' => $request['slug'], 'content' => $content, 'previous' => $previous['content'] ?? ''];

                return Http::response([
                    'id' => $id, 'link' => $this->wp['link'] ?? 'https://shop.test/'.$request['slug'].'/', 'content' => ['raw' => $content],
                    '_links' => $this->wp['filtered'] ? [] : ['https://api.w.org/action-unfiltered-html' => [['href' => $url]]],
                ], isset($match[1]) ? 200 : 201);
            }
            foreach (array_reverse($this->wp['pages']) as $stored) {
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

    /** A page from the Studio template, with the launch copy it draws on approved and a confirmed availability. */
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

    /** A finished banner of the pack, with its content item approved when $approved. */
    private function banner(CampaignPack $pack, User $owner, string $headline, bool $approved = false): BannerCreative
    {
        $version = $pack->versions()->firstOrFail();
        $batch = $pack->product->brand->workspace->bannerGenerationBatches()->create([
            'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id,
            'requested_by_user_id' => $owner->id, 'kind' => 'included', 'requested_count' => 1, 'status' => 'completed',
        ]);
        $path = 'creatives/'.Str::random(8).'.png';
        Storage::disk('local')->put($path, 'png');
        $creative = $batch->creatives()->create([
            'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id, 'sequence' => 1, 'direction' => 'Product first',
            'layout' => 'bottom_panel', 'headline' => $headline, 'cta' => 'Shop now', 'prompt' => 'Banner', 'status' => 'completed',
            'disk' => 'local', 'output_path' => $path, 'width' => 1080, 'height' => 1350,
        ]);
        app(ContentLibrary::class)->syncPack($pack);
        if ($approved) {
            ContentItem::query()->where('banner_creative_id', $creative->id)->update(['status' => 'approved']);
        }

        return $creative;
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
