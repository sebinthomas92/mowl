<?php

namespace Tests\Feature;

use App\Livewire\StoreConnections;
use App\Models\Page;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PageImages;
use App\Services\Pages\PagePublisher;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\ShopifyStore;
use App\Services\Shopify\ShopifyApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ShopifyPagesTest extends TestCase
{
    use RefreshDatabase;

    private string $html = '';

    private int $creates = 0;

    private bool $stale = false;

    private bool $loseAnswer = false;

    private bool $pageExists = true;

    private bool $refreshLimited = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.client_id' => 'test-client', 'shopify.client_secret' => 'test-secret', 'pages.allow_private_hosts' => true]);
        Http::preventStrayRequests();
    }

    public function test_owner_authorizes_on_shopify_without_exposing_a_token(): void
    {
        [$owner, $workspace] = $this->fixture();
        $component = Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')
            ->set('driver', 'shopify')->set('name', 'Shop')->set('siteUrl', 'northline.myshopify.com')
            ->call('save')->assertHasNoErrors();
        $url = $component->effects['redirect'];
        $this->assertStringStartsWith('https://northline.myshopify.com/admin/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('write_online_store_pages,write_files', $query['scope']);
        $this->assertSame(route('shopify.callback'), $query['redirect_uri']);
        $this->assertSame($workspace->id, session('shopify.authorization.workspace_id'));
        $this->assertDatabaseCount('store_connections', 0);
        $component->assertDontSee('test-secret');
    }

    public function test_callback_checks_signature_and_state_and_stores_encrypted_expiring_credentials(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->fakeShopify();
        $context = $this->context($owner, $workspace);
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertRedirect(route('workspace.settings').'#connections')->assertSessionMissing('shopify.authorization');
        $store = StoreConnection::sole();
        $this->assertSame('Shopify', $store->label());
        $this->assertStringNotContainsString('access-token', $store->getRawOriginal('secret'));
        $this->assertStringNotContainsString('refresh-token', $store->toJson());
        $this->assertSame(now()->timestamp + 3600, json_decode($store->secret, true)['expires_at']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/access_token') && $r['expiring'] === 1);
        $this->actingAs($owner)->get($this->callbackUrl($context))->assertForbidden();
    }

    public function test_callback_rejects_wrong_state_tampering_expired_requests_and_workspace_changes(): void
    {
        [$owner, $workspace] = $this->fixture();
        $context = $this->context($owner, $workspace);
        foreach ([['state' => 'wrong'], ['hmac' => 'invalid'], ['timestamp' => (string) (now()->timestamp - 1000)], ['shop' => 'northline.myshopify.com.attacker.test']] as $change) {
            $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
                ->get($this->callbackUrl($context, $change))->assertForbidden();
        }
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id + 999, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_only_owners_connect_and_callback_rechecks_the_role(): void
    {
        [$owner, $workspace] = $this->fixture();
        $context = $this->context($owner, $workspace);
        $workspace->users()->updateExistingPivot($owner->id, ['role' => 'member']);
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')->assertForbidden();
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_addresses_and_missing_app_configuration_do_not_start_oauth(): void
    {
        [$owner] = $this->fixture();
        foreach (['http://northline.myshopify.com', 'northline.myshopify.com.attacker.test', 'northline.myshopify.com/admin', 'user@northline.myshopify.com', 'localhost', 'northline.myshopify.com:443'] as $url) {
            Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')->set('driver', 'shopify')->set('name', 'Shop')->set('siteUrl', $url)
                ->call('save')->assertHasErrors('siteUrl');
        }
        config(['shopify.client_id' => null]);
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')->set('driver', 'shopify')->set('name', 'Shop')->set('siteUrl', 'northline.myshopify.com')
            ->call('save')->assertHasErrors('siteUrl');
        Http::assertNothingSent();
    }

    public function test_shopify_permission_failure_does_not_save_a_connection(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['*' => Http::response(['access_token' => 'access-token', 'scope' => 'read_products'])]);
        $context = $this->context($owner, $workspace);
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertSessionHas('shopify_error');
        $this->assertDatabaseCount('store_connections', 0);
    }

    public function test_existing_connections_are_reconnected_in_place_and_cannot_change_shops(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $this->fakeShopify();
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open')->set('driver', 'shopify')->set('name', 'Duplicate')->set('siteUrl', $store->site_url)
            ->call('save')->assertHasErrors('siteUrl');
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('open', $store->id)->set('siteUrl', 'other.myshopify.com')
            ->call('save')->assertHasErrors('siteUrl');
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $store->id, 'remote_id' => 'gid://shopify/Page/1']);
        $context = $this->context($owner, $workspace) + ['store_id' => $store->id];
        $context['store_id'] = $store->id;
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertSessionHas('shopify_connected');
        $this->assertDatabaseCount('store_connections', 1);
        $this->assertSame('gid://shopify/Page/1', $page->fresh()->remote_id);
    }

    public function test_publishing_updates_verifies_and_rolls_back_using_existing_page_versions(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $this->fakeShopify();
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $store->id]);
        $publisher = app(PagePublisher::class);
        $attempt = $publisher->publish($page, $owner);
        $this->assertSame('live', $attempt->status, $attempt->error ?? '');
        $page->refresh();
        $this->assertSame('https://store.example.com/pages/'.$page->slug, $page->remote_url);
        app(PageBuilder::class)->edit($page, fn (Page $locked) => $locked->update(['headline' => 'New headline']));
        $attempt = $publisher->publish($page->fresh(), $owner);
        $this->assertSame('live', $attempt->status, $attempt->error ?? '');
        $this->assertStringContainsString('New headline', $this->html);
        $this->assertSame('live', $publisher->rollback($page->fresh(), $owner, 1)->status);
        $this->assertStringNotContainsString('New headline', $this->html);
        $this->assertSame(1, $this->creates);
        $this->assertSame(2, $page->fresh()->draft_version);
        Http::assertSent(fn (Request $r) => str_contains($r['query'] ?? '', 'MarketingOwlPageCreate') && data_get($r->data(), 'variables.page.isPublished') === true);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://store.example.com/') && ! $r->hasHeader('X-Shopify-Access-Token'));
    }

    public function test_lost_create_response_recovers_our_page_without_overwriting_an_unrelated_page(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $this->fakeShopify();
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $store->id]);
        $this->loseAnswer = true;
        $publisher = app(PagePublisher::class);
        $this->assertSame('failed', $publisher->publish($page, $owner)->status);
        $this->assertStringContainsString('may have saved', $page->fresh()->last_error);
        $this->loseAnswer = false;
        $attempt = $publisher->publish($page->fresh(), $owner);
        $this->assertSame('live', $attempt->status, $attempt->error ?? '');
        $this->assertSame(1, $this->creates);
    }

    public function test_a_password_page_or_stale_response_never_counts_as_live(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $this->fakeShopify();
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $store->id]);
        $this->stale = true;
        config(['pages.verify_checks' => 1]);
        $this->assertSame('unverified', app(PagePublisher::class)->publish($page, $owner)->status);
        $this->assertNull($page->fresh()->live_version);
    }

    public function test_expiring_tokens_refresh_and_retryable_failures_keep_the_refresh_token(): void
    {
        [, $workspace] = $this->fixture();
        $store = $this->store($workspace);
        $store->update(['secret' => json_encode(['access_token' => 'old', 'refresh_token' => 'refresh-token', 'expires_at' => now()->timestamp - 1])]);
        $this->fakeShopify();
        $this->assertSame('access-token', app(ShopifyApi::class)->accessToken($store));
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-token');
        $store->update(['secret' => json_encode(['access_token' => 'old', 'refresh_token' => 'refresh-token', 'expires_at' => now()->timestamp - 1])]);
        $this->refreshLimited = true;
        try {
            app(ShopifyApi::class)->accessToken($store);
            $this->fail('A rate-limited refresh must fail safely.');
        } catch (StoreException $e) {
            $this->assertFalse($e->auth);
            $this->assertSame('refresh-token', json_decode($store->fresh()->secret, true)['refresh_token']);
        }
    }

    public function test_pending_images_are_retained_and_reused_without_leaking_the_admin_token(): void
    {
        [, $workspace] = $this->fixture();
        $store = $this->store($workspace);
        $this->mock(PageImages::class)->shouldReceive('contents')->andReturn(['bytes' => 'image-bytes', 'mime' => 'image/png', 'filename' => 'hero.png']);
        $ready = false;
        Http::fake(function (Request $request) use (&$ready) {
            if ($request->url() === 'https://uploads.example.com/stage') {
                $this->assertFalse($request->hasHeader('X-Shopify-Access-Token'));

                return Http::response('', 201);
            }
            $data = match (true) {
                str_contains($request['query'], 'MarketingOwlStage') => ['stagedUploadsCreate' => ['stagedTargets' => [['url' => 'https://uploads.example.com/stage', 'resourceUrl' => 'https://uploads.example.com/image', 'parameters' => [['name' => 'key', 'value' => 'image']]]], 'userErrors' => []]],
                str_contains($request['query'], 'MarketingOwlFileStatus') => ['node' => ['id' => 'gid://shopify/MediaImage/1', 'fileStatus' => $ready ? 'READY' : 'PROCESSING', 'image' => $ready ? ['url' => 'https://cdn.shopify.com/hero.png'] : null]],
                default => ['fileCreate' => ['files' => [['id' => 'gid://shopify/MediaImage/1']], 'userErrors' => []]],
            };

            return Http::response(['data' => $data]);
        });
        $client = app(ShopifyStore::class);
        try {
            $client->uploadImage($store, ['key' => 'media-1', 'alt' => 'Hero'], $workspace->id);
            $this->fail('Processing images cannot be published yet.');
        } catch (StoreException $e) {
            $this->assertStringContainsString('still processing', $e->getMessage());
        }
        $this->assertSame('gid://shopify/MediaImage/1', $store->fresh()->media['media-1']['id']);
        $ready = true;
        $this->assertSame('https://cdn.shopify.com/hero.png', $client->uploadImage($store, ['key' => 'media-1'], $workspace->id)['url']);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r['query'] ?? '', 'mutation MarketingOwlStage')));
    }

    public function test_an_unrelated_page_at_the_requested_address_is_never_overwritten(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $store->id]);
        $this->html = '<div data-mowl-page="999999">Other page</div>';
        $this->fakeShopify();
        $attempt = app(PagePublisher::class)->publish($page, $owner);
        $this->assertSame('failed', $attempt->status);
        $this->assertStringContainsString('already used', $attempt->error);
        $this->assertSame(0, $this->creates);
        Http::assertNotSent(fn (Request $request) => str_contains($request['query'] ?? '', 'mutation MarketingOwlPage'));
    }

    public function test_a_deleted_remote_page_is_recreated_and_permission_errors_require_reconnect(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $store->id, 'remote_id' => 'gid://shopify/Page/1', 'remote_store' => $store->remoteKey()]);
        $this->pageExists = false;
        $this->fakeShopify();
        $this->assertSame('live', app(PagePublisher::class)->publish($page, $owner)->status);
        $this->assertSame(1, $this->creates);
        // A new fake factory isolates the permission response from the previously registered callback.
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['errors' => [['extensions' => ['code' => 'ACCESS_DENIED']]]])]);
        Livewire::actingAs($owner)->test(StoreConnections::class)->call('check', $store->id);
        $this->assertSame('needs_attention', $store->fresh()->status);
    }

    public function test_public_verification_blocks_private_redirects_without_sending_a_token(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $store = $this->store($workspace);
        $page = app(PageBuilder::class)->start($product, $owner);
        config(['pages.allow_private_hosts' => false]);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://192.168.1.1/private'])]);
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('private or reserved');
        app(ShopifyStore::class)->fetchPublic($store, $page, 'https://1.1.1.1/pages/launch');
    }

    public function test_another_workspaces_store_and_brand_cannot_be_connected_through_callback(): void
    {
        [$owner, $workspace] = $this->fixture();
        [$other, $elsewhere] = $this->fixture();
        $store = $this->store($elsewhere);
        $context = $this->context($owner, $workspace);
        $context['store_id'] = $store->id;
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertNotFound();
        $context['store_id'] = null;
        $context['brand_id'] = $elsewhere->brands()->first()->id;
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id, 'shopify.authorization' => $context])
            ->get($this->callbackUrl($context))->assertForbidden();
        Http::assertNothingSent();
    }

    private function fakeShopify(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/oauth/access_token')) {
                if ($this->refreshLimited) {
                    return Http::response([], 429);
                }

                return Http::response(['access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600, 'scope' => implode(',', config('shopify.scopes'))]);
            }
            if (str_starts_with($request->url(), 'https://store.example.com/')) {
                return Http::response($this->stale ? '<html>Password required</html>' : $this->html);
            }
            $query = $request['query'] ?? '';
            $variables = json_decode($request->body(), true)['variables'] ?? [];
            if (str_contains($query, 'MarketingOwlConnection')) {
                return Http::response(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://store.example.com']], 'currentAppInstallation' => ['accessScopes' => [['handle' => 'write_online_store_pages'], ['handle' => 'write_files']]]]]);
            }
            if (str_contains($query, 'MarketingOwlRecoverPage')) {
                return Http::response(['data' => ['pages' => ['nodes' => $this->html ? [['id' => 'gid://shopify/Page/1', 'handle' => substr($variables['query'], 7), 'body' => $this->html]] : []]]]);
            }
            if (str_contains($query, 'query MarketingOwlPage(')) {
                return Http::response(['data' => ['page' => $this->pageExists ? ['id' => 'gid://shopify/Page/1'] : null]]);
            }
            $operation = str_contains($query, 'MarketingOwlPageUpdate') ? 'pageUpdate' : 'pageCreate';
            if ($operation === 'pageCreate') {
                $this->creates++;
            }
            $this->html = $variables['page']['body'];
            if ($this->loseAnswer) {
                return Http::failedConnection();
            }

            return Http::response(['data' => [$operation => ['page' => ['id' => 'gid://shopify/Page/1', 'handle' => $variables['page']['handle']], 'userErrors' => []]]]);
        });
    }

    private function store(Workspace $workspace): StoreConnection
    {
        return StoreConnection::create(['workspace_id' => $workspace->id, 'name' => 'Shop', 'driver' => 'shopify', 'site_url' => 'https://northline.myshopify.com', 'api_base' => 'https://store.example.com', 'secret' => json_encode(['access_token' => 'access-token'])]);
    }

    private function context(User $owner, Workspace $workspace): array
    {
        return ['shop' => 'northline.myshopify.com', 'state' => 'test-state', 'user_id' => $owner->id, 'workspace_id' => $workspace->id, 'brand_id' => null, 'store_id' => null, 'name' => 'Shop', 'created_at' => now()->timestamp];
    }

    private function callbackUrl(array $context, array $changes = []): string
    {
        $query = ['code' => 'test-code', 'shop' => $context['shop'], 'state' => $context['state'], 'timestamp' => (string) now()->timestamp];
        ksort($query);
        $query['hmac'] = hash_hmac('sha256', http_build_query($query), 'test-secret');

        return route('shopify.callback').'?'.http_build_query(array_replace($query, $changes));
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Shopify Test']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => 'Reading Stand', 'summary' => 'A stand for books.']);

        $product->sourceSnapshots()->create(['url' => 'https://store.example.com/products/reading-stand', 'content_hash' => hash('sha256', 'reading-stand'), 'status' => 'ready']);

        return [$owner, $workspace, $product];
    }
}
