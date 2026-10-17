<?php

namespace App\Services\Shopify;

use App\Models\StoreConnection;
use App\Models\Workspace;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\ShopifyStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopifyAuthorization
{
    public function __construct(private ShopifyApi $api) {}

    public function begin(Request $request, array $context): string
    {
        if (! $this->api->configured()) {
            throw new StoreException('Shopify connections are not enabled yet. Please contact your workspace administrator.');
        }
        $shop = $this->api->domain($context['site_url']);
        $existing = StoreConnection::query()->where('driver', 'shopify')->where('site_url', 'https://'.$shop)->first();
        if ($existing && $existing->id !== ($context['store_id'] ?? null)) {
            throw new StoreException('This Shopify store is already connected. Reconnect its existing connection instead.');
        }
        if ($context['store_id'] ?? null) {
            $store = StoreConnection::query()->where('workspace_id', $context['workspace_id'])->where('driver', 'shopify')->findOrFail($context['store_id']);
            if ($this->api->domain($store->site_url) !== $shop) {
                throw new StoreException('Reconnect using this store’s original Shopify address.');
            }
        }
        $state = Str::random(64);
        session()->put('shopify.authorization', $context + [
            'shop' => $shop, 'state' => $state, 'user_id' => $request->user()->id, 'created_at' => now()->timestamp,
        ]);

        return 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => config('shopify.client_id'), 'scope' => implode(',', config('shopify.scopes')),
            'redirect_uri' => route('shopify.callback'), 'state' => $state,
        ]);
    }

    public function finish(Request $request): StoreConnection
    {
        $context = $request->session()->get('shopify.authorization');
        $params = $request->query();
        abort_unless($this->api->configured() && is_array($context) && $context['user_id'] === $request->user()->id, 403);
        foreach (['state', 'shop', 'code', 'hmac', 'timestamp'] as $key) {
            abort_unless(isset($params[$key]) && is_string($params[$key]), 403);
        }
        abort_unless(hash_equals($context['state'], $params['state']) && $context['shop'] === $params['shop']
            && now()->timestamp - $context['created_at'] <= 900
            && ctype_digit($params['timestamp']) && abs(now()->timestamp - (int) $params['timestamp']) <= 900, 403);
        $hmac = $params['hmac'];
        unset($params['hmac']);
        abort_if(count(array_filter($params, fn ($value) => ! is_string($value))) > 0, 403);
        ksort($params);
        abort_unless(hash_equals(hash_hmac('sha256', http_build_query($params), config('shopify.client_secret')), $hmac), 403);
        $workspace = Workspace::findOrFail($context['workspace_id']);
        abort_unless($request->user()->workspaces()->whereKey($workspace->id)->wherePivot('role', 'owner')->exists(), 403);
        abort_unless((int) $request->session()->get('current_workspace_id') === $workspace->id, 403);
        abort_if($context['brand_id'] && ! $workspace->brands()->whereKey($context['brand_id'])->exists(), 403);
        $store = $context['store_id'] ? StoreConnection::query()->where('workspace_id', $workspace->id)->where('driver', 'shopify')->findOrFail($context['store_id']) : new StoreConnection;
        abort_if($store->exists && $this->api->domain($store->site_url) !== $context['shop'], 403);
        // Consume valid state once, before exchanging the authorization code.
        $request->session()->forget('shopify.authorization');

        return Cache::lock('shopify-connect-'.$context['shop'], 90)->block(5, function () use ($store, $context, $params, $request, $workspace): StoreConnection {
            $existing = StoreConnection::query()->where('driver', 'shopify')->where('site_url', 'https://'.$context['shop'])->first();
            if ($existing && $existing->id !== $store->id) {
                throw new StoreException('This Shopify store was connected already. Reconnect its existing connection instead.');
            }
            $credentials = $this->api->token($context['shop'], ['code' => $params['code'], 'expiring' => 1]);
            if ($this->api->missingScopes(explode(',', $credentials['scope'] ?? ''))) {
                throw new StoreException('Shopify did not grant permission to publish pages and upload images. Connect again and approve both permissions.');
            }
            $store->fill([
                'workspace_id' => $workspace->id, 'brand_id' => $context['brand_id'], 'name' => $context['name'],
                'driver' => 'shopify', 'site_url' => 'https://'.$context['shop'], 'username' => null,
                'secret' => json_encode($credentials, JSON_THROW_ON_ERROR),
            ]);
            $result = app(ShopifyStore::class)->check($store);

            return DB::transaction(function () use ($store, $result, $request, $workspace): StoreConnection {
                $store->fill($result + ['status' => 'connected', 'last_error' => null, 'checked_at' => now()]);
                $store->created_by_user_id ??= $request->user()->id;
                $store->save();
                $workspace->auditEvents()->create([
                    'actor_user_id' => $request->user()->id, 'event' => 'shopify_store_connected',
                    'subject_type' => StoreConnection::class, 'subject_id' => $store->id,
                    'metadata' => ['site_url' => $store->site_url],
                ]);

                return $store;
            });
        });
    }
}
