<?php

namespace App\Services\Shopify;

use App\Models\StoreConnection;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\PublicAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Server-side Shopify requests. Credentials never enter component state or public URLs. */
class ShopifyApi
{
    public function domain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https://#', '', $value);
        $value = rtrim($value, '/');
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $value)) {
            throw new StoreException('Enter the store’s original address, such as northline.myshopify.com.');
        }

        return $value;
    }

    public function configured(): bool
    {
        return filled(config('shopify.client_id')) && filled(config('shopify.client_secret'));
    }

    public function token(string $shop, array $parameters): array
    {
        $url = 'https://'.$this->domain($shop).'/admin/oauth/access_token';
        $response = $this->request($url, [
            'client_id' => config('shopify.client_id'), 'client_secret' => config('shopify.client_secret'),
        ] + $parameters);
        if (! $response->successful() || ! is_string($response->json('access_token')) || $response->json('access_token') === '') {
            throw new StoreException('Shopify could not authorize this connection. Reconnect the store.', auth: in_array($response->status(), [400, 401, 403], true));
        }
        $data = $response->json();

        return [
            'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => (int) ($data['expires_in'] ?? 0) > 0 ? now()->timestamp + (int) $data['expires_in'] : null,
            'scope' => $data['scope'] ?? null,
        ];
    }

    public function accessToken(StoreConnection $store): string
    {
        $credentials = json_decode((string) $store->secret, true) ?: [];
        if (empty($credentials['access_token'])) {
            throw new StoreException('Reconnect this Shopify store in Settings.', auth: true);
        }
        if (! isset($credentials['expires_at']) || $credentials['expires_at'] > now()->timestamp + 60) {
            return $credentials['access_token'];
        }
        if (! $store->exists) {
            throw new StoreException('Shopify authorization expired. Connect the store again.', auth: true);
        }

        // The lock prevents concurrent requests from rotating the same refresh token twice.
        return DB::transaction(function () use ($store): string {
            $locked = StoreConnection::query()->lockForUpdate()->findOrFail($store->id);
            $credentials = json_decode((string) $locked->secret, true) ?: [];
            if (empty($credentials['access_token'])) {
                throw new StoreException('Reconnect this Shopify store in Settings.', auth: true);
            }
            if (isset($credentials['expires_at']) && $credentials['expires_at'] <= now()->timestamp + 60) {
                if (empty($credentials['refresh_token'])) {
                    throw new StoreException('Shopify authorization expired. Reconnect the store.', auth: true);
                }
                $credentials = $this->token($this->domain($locked->site_url), [
                    'grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'],
                ]);
                $locked->update(['secret' => json_encode($credentials, JSON_THROW_ON_ERROR)]);
            }
            $store->setRawAttributes($locked->getAttributes(), true);

            return $credentials['access_token'];
        });
    }

    public function query(StoreConnection $store, string $query, array $variables = []): array
    {
        $token = $this->accessToken($store);
        $url = 'https://'.$this->domain($store->site_url).'/admin/api/'.config('shopify.api_version').'/graphql.json';
        $response = $this->request($url, ['query' => $query, 'variables' => (object) $variables], $token);
        if (! $response->successful()) {
            throw new StoreException('Shopify could not complete the request (HTTP '.$response->status().').', auth: in_array($response->status(), [401, 403], true), unsure: $response->status() >= 500);
        }
        if ($response->json('errors')) {
            $codes = collect($response->json('errors'))->pluck('extensions.code');
            throw new StoreException($codes->contains('ACCESS_DENIED') ? 'Shopify permissions changed. Reconnect the store.' : 'Shopify could not complete the request. Try again shortly.', auth: $codes->contains('ACCESS_DENIED'));
        }
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new StoreException('Shopify returned an incomplete response.', unsure: true);
        }

        return $data;
    }

    public function request(string $url, array $body, ?string $token = null): Response
    {
        $binding = PublicAddress::binding($url);
        $http = Http::acceptJson()->timeout(config('pages.timeout_seconds'))
            ->withOptions(['allow_redirects' => false] + ($binding ? ['curl' => [CURLOPT_RESOLVE => [$binding]]] : []));
        if ($token) {
            $http = $http->withHeaders(['X-Shopify-Access-Token' => $token]);
        }
        try {
            return $http->post($url, $body);
        } catch (ConnectionException) {
            // Never include transport messages: they can contain signed upload URLs or credentials.
            throw new StoreException('Shopify could not be reached. Try again shortly.', unsure: true);
        }
    }

    public function missingScopes(array $granted): array
    {
        return array_values(array_filter(config('shopify.scopes'), fn ($scope) => ! in_array($scope, $granted, true)
            && ! (str_starts_with($scope, 'read_') && in_array('write_'.substr($scope, 5), $granted, true))));
    }
}
