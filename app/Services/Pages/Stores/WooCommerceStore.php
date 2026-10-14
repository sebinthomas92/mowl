<?php

namespace App\Services\Pages\Stores;

use App\Models\Page;
use App\Models\StoreConnection;
use App\Services\Pages\PageImages;
use App\Services\Pages\StoreException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Publishes pages to a WooCommerce store through the WordPress REST API.
 *
 * WooCommerce's own consumer keys only open the wc/v3 product endpoints; pages and media belong to
 * WordPress, so the connection uses a WordPress user and an application password (Users → Profile).
 */
class WooCommerceStore implements StoreClient
{
    public function __construct(private PageImages $images) {}

    public function check(StoreConnection $store): array
    {
        $index = null;
        $apiBase = null;
        foreach ([rtrim($store->site_url, '/').'/wp-json/', rtrim($store->site_url, '/').'/?rest_route=/'] as $candidate) {
            $response = $this->send(fn (PendingRequest $http) => $http->get($candidate), $candidate);
            if ($response->redirect() && $response->header('Location')) {
                $target = (string) UriResolver::resolve(new Uri($candidate), new Uri($response->header('Location')));
                throw new StoreException('The site redirects to '.preg_replace('#/(wp-json/?|\?rest_route=.*)$#', '', $target).'. Use that address as the store URL.');
            }
            $json = $response->successful() ? $response->json() : null;
            if (is_array($json) && in_array('wp/v2', $json['namespaces'] ?? [], true)) {
                [$index, $apiBase] = [$json, $candidate];
                break;
            }
        }
        if (! $index) {
            throw new StoreException('This address does not look like a WordPress site. Check the store URL; the WordPress REST API must be switched on.');
        }

        $store->api_base = $apiBase;
        $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->get($this->endpoint($store, 'wp/v2/users/me', ['context' => 'edit'])), $apiBase);
        if (in_array($response->status(), [401, 403], true)) {
            // WordPress 5.6+ lists the auth methods it accepts; an empty list means application passwords are off.
            if (isset($index['authentication']) && empty($index['authentication']['application-passwords'])) {
                throw new StoreException('This site has application passwords switched off. WordPress only allows them on https:// sites, and some security plugins turn them off. Switch them on, then try again.', auth: true);
            }
            throw new StoreException('WordPress rejected the username or application password. Use an application password from Users, then Profile, not your login password.', auth: true);
        }
        if (! $response->successful()) {
            throw $this->failure($response, 'check the connection');
        }
        $capabilities = $response->json('capabilities') ?? [];
        if (empty($capabilities['publish_pages']) || empty($capabilities['edit_pages'])) {
            throw new StoreException('This WordPress user cannot publish pages. Use an Editor or Administrator account.', auth: true);
        }
        if (empty($capabilities['upload_files'])) {
            throw new StoreException('This WordPress user cannot upload images. Use an Editor or Administrator account.', auth: true);
        }

        return ['api_base' => $apiBase, 'has_woocommerce' => in_array('wc/v3', $index['namespaces'] ?? [], true)];
    }

    public function uploadImage(StoreConnection $store, array $ref, int $workspaceId): array
    {
        $image = $this->images->contents($ref, $workspaceId);
        if (! $image) {
            throw new StoreException('An image on this page could not be read. Choose another image for the hero, then publish again.');
        }
        $url = $this->endpoint($store, 'wp/v2/media');
        $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)
            ->withHeaders(['Content-Disposition' => 'attachment; filename="'.$image['filename'].'"'])
            ->withBody($image['bytes'], $image['mime'])
            ->post($url), $url);
        if (! $response->successful() || ! $response->json('id') || ! $response->json('source_url')) {
            throw $this->failure($response, 'upload an image');
        }

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('source_url')];
    }

    public function putPage(StoreConnection $store, Page $page, ?string $remoteId, string $title, string $html): array
    {
        $payload = ['title' => $title, 'slug' => $page->slug, 'content' => $html, 'status' => 'publish'];
        $response = null;
        if ($remoteId) {
            $url = $this->endpoint($store, 'wp/v2/pages/'.rawurlencode($remoteId));
            $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->post($url, $payload), $url);
            if (in_array($response->status(), [404, 410], true)) {
                $response = null; // Deleted in the store; publish it again as a new page.
            }
        }
        if (! $response) {
            $url = $this->endpoint($store, 'wp/v2/pages');
            $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->post($url, $payload), $url);
        }
        if (! $response->successful() || ! $response->json('id') || ! $response->json('link')) {
            throw $this->failure($response, 'save the page');
        }

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('link')];
    }

    public function fetchPublic(StoreConnection $store, Page $page, string $url): string
    {
        $current = $url.(str_contains($url, '?') ? '&' : '?').'mowl_verify='.time();
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $response = $this->send(fn (PendingRequest $http) => $http->accept('text/html')->get($current), $current);
            if (! $response->redirect()) {
                if (! $response->successful()) {
                    throw new StoreException("The page address returned {$response->status()}.");
                }

                return substr($response->body(), 0, 4 * 1024 * 1024);
            }
            $location = $response->header('Location');
            if (! $location) {
                break;
            }
            $current = (string) UriResolver::resolve(new Uri($current), new Uri($location));
        }

        throw new StoreException('The page address redirects too many times.');
    }

    /** One request to a public address, pinned to the IP that passed the address check. */
    private function send(callable $request, string $url): Response
    {
        $binding = PublicAddress::binding($url);
        $http = Http::acceptJson()
            ->withUserAgent('MarketingOwl/1.0 (+page-publishing)')
            ->timeout(config('pages.timeout_seconds'))
            ->withOptions(['allow_redirects' => false] + ($binding ? ['curl' => [CURLOPT_RESOLVE => [$binding]]] : []));
        try {
            return $request($http);
        } catch (ConnectionException $exception) {
            throw new StoreException('The store could not be reached: '.mb_strimwidth($exception->getMessage(), 0, 160, '…'), unsure: true);
        }
    }

    private function authed(PendingRequest $http, StoreConnection $store): PendingRequest
    {
        return $http->withBasicAuth((string) $store->username, (string) $store->secret);
    }

    /** A REST route on either URL form WordPress offers (/wp-json/… or ?rest_route=/…). */
    private function endpoint(StoreConnection $store, string $route, array $query = []): string
    {
        $base = (string) $store->api_base;
        $url = str_contains($base, 'rest_route=') ? $base.$route : rtrim($base, '/').'/'.$route;

        return $query ? $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query) : $url;
    }

    private function failure(Response $response, string $action): StoreException
    {
        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            return new StoreException("The store rejected the request ({$status}): the connection needs new credentials.", auth: true);
        }
        if ($response->redirect()) {
            return new StoreException("The store redirected the request to {$response->header('Location')}. Update the store URL to that address.");
        }
        $message = $response->json('message');

        return new StoreException("The store could not {$action} ({$status})".(is_string($message) && $message !== '' ? ': '.mb_strimwidth(strip_tags($message), 0, 200, '…') : '.'));
    }
}
