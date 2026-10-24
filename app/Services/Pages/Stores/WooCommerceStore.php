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
    /**
     * The page template block themes (Twenty Twenty-Four and Twenty Twenty-Five among them) offer
     * without the page title, so the theme does not print the page's name above the hero.
     */
    public const NO_TITLE_TEMPLATE = 'page-no-title';

    public function __construct(private PageImages $images) {}

    public function check(StoreConnection $store): array
    {
        [$index, $apiBase] = $this->discover($store);

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

    /**
     * Where WordPress asks its signed-in user to approve MarketingOwl (WordPress 5.6+). Approving sends
     * the user back with a new application password, so nobody copies one by hand.
     *
     * @return array{api_base: string, url: string}
     */
    public function authorization(StoreConnection $store): array
    {
        [$index, $apiBase] = $this->discover($store);
        $url = $index['authentication']['application-passwords']['endpoints']['authorization'] ?? null;
        if (! is_string($url) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new StoreException(isset($index['authentication'])
                ? 'This site has application passwords switched off. WordPress only allows them on https:// sites, and some security plugins turn them off. Switch them on, then try again.'
                : 'This WordPress is too old to approve apps (it needs version 5.6 or later). Update WordPress, or use an application password instead.');
        }

        return ['api_base' => $apiBase, 'url' => $url];
    }

    /** @return array{0: array, 1: string} the REST index and the URL form it answered on */
    private function discover(StoreConnection $store): array
    {
        foreach ([rtrim($store->site_url, '/').'/wp-json/', rtrim($store->site_url, '/').'/?rest_route=/'] as $candidate) {
            $response = $this->send(fn (PendingRequest $http) => $http->get($candidate), $candidate);
            if ($response->redirect() && $response->header('Location')) {
                $target = (string) UriResolver::resolve(new Uri($candidate), new Uri($response->header('Location')));
                throw new StoreException('The site redirects to '.preg_replace('#/(wp-json/?|\?rest_route=.*)$#', '', $target).'. Use that address as the store URL.');
            }
            $json = $response->successful() ? $response->json() : null;
            if (is_array($json) && in_array('wp/v2', $json['namespaces'] ?? [], true)) {
                return [$json, $candidate];
            }
        }

        throw new StoreException('This address does not look like a WordPress site. Check the store URL; the WordPress REST API must be switched on.');
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

        $width = (int) $response->json('media_details.width');
        $height = (int) $response->json('media_details.height');

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('source_url'), 'width' => $width > 0 ? $width : null, 'height' => $height > 0 ? $height : null];
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
            // A new page asks for the title-less template. Themes without one reject the template before
            // anything is saved, so the page is then created with the theme's default template. Later
            // publishes leave the template alone, so a template chosen in WordPress stays.
            $url = $this->endpoint($store, 'wp/v2/pages');
            $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->post($url, $payload + ['template' => self::NO_TITLE_TEMPLATE]), $url);
            if ($this->rejectedTemplate($response)) {
                $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->post($url, $payload), $url);
            }
        }
        if (! $response->successful() || ! $response->json('id') || ! $response->json('link')) {
            throw $this->failure($response, 'save the page');
        }

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('link'), 'warning' => $this->strippedLayout($html, $response)];
    }

    /** WordPress answers an unknown template with a 400 that names the template parameter. */
    private function rejectedTemplate(Response $response): bool
    {
        return $response->status() === 400 && $response->json('code') === 'rest_invalid_param' && is_array($response->json('data.params'))
            && array_key_exists('template', $response->json('data.params'));
    }

    /**
     * WordPress filters post content for users without the unfiltered_html capability (Editors on
     * multisite, sites with DISALLOW_UNFILTERED_HTML). Before WordPress 7.0 that filter drops the
     * "display" property, which the page's columns and grids rely on. The saved content tells: the
     * response carries it raw, and an action link only when the user is unfiltered.
     */
    private function strippedLayout(string $html, Response $response): ?string
    {
        $raw = $response->json('content.raw');
        $unfiltered = array_key_exists('https://api.w.org/action-unfiltered-html', (array) $response->json('_links'));
        if ($unfiltered || ! is_string($raw)) {
            return null;
        }
        if (substr_count($raw, 'display:') >= substr_count($html, 'display:')) {
            return null;
        }

        return 'The store accepted the page but removed its layout styles, so columns show one under the other. WordPress does this for users who cannot post unfiltered HTML before version 7.0. Connect the store with an Administrator account, or update WordPress, then publish again.';
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

    /**
     * The store's product for a Marketing Owl product: by the slug of its product page first, then by name.
     *
     * @param  array<int, string>  $slugs
     * @return array{id: int, name: string}|null
     */
    public function findProduct(StoreConnection $store, array $slugs, string $name): ?array
    {
        foreach (array_unique(array_filter($slugs)) as $slug) {
            $match = $this->products($store, ['slug' => $slug]);
            if (count($match) === 1) {
                return $match[0];
            }
        }
        $found = $this->products($store, ['search' => $name, 'per_page' => 10]);
        $exact = array_values(array_filter($found, fn (array $product) => mb_strtolower(trim($product['name'])) === mb_strtolower(trim($name))));

        return count($exact) === 1 ? $exact[0] : (count($found) === 1 ? $found[0] : null);
    }

    /**
     * Paid orders containing the product, by the day they were placed (store time).
     *
     * @return array{days: array<string, array{orders: int, revenue: float}>, currency: ?string, truncated: bool}
     */
    public function productOrders(StoreConnection $store, int $productId, string $from, string $to): array
    {
        $days = [];
        $currency = null;
        $pages = (int) config('campaigns.store_order_pages', 20);
        for ($page = 1; $page <= $pages; $page++) {
            $url = $this->endpoint($store, 'wc/v3/orders', [
                'product' => $productId, 'after' => $from.'T00:00:00', 'before' => $to.'T23:59:59',
                'status' => 'processing,completed', 'per_page' => 100, 'page' => $page, 'orderby' => 'date', 'order' => 'asc',
            ]);
            $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->get($url), $url);
            if (in_array($response->status(), [401, 403], true)) {
                throw new StoreException('The store did not allow reading orders. Connect it with a WordPress user who can manage WooCommerce (Shop manager or Administrator).', auth: true);
            }
            if (! $response->successful() || ! is_array($response->json())) {
                throw $this->failure($response, 'list orders');
            }
            foreach ($response->json() as $order) {
                $lines = array_filter($order['line_items'] ?? [], fn ($line) => (int) ($line['product_id'] ?? 0) === $productId);
                $date = substr((string) ($order['date_created'] ?? ''), 0, 10);
                if (! $lines || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                $orderCurrency = is_string($order['currency'] ?? null) ? strtoupper($order['currency']) : null;
                if ($currency && $orderCurrency && $orderCurrency !== $currency) {
                    throw new StoreException("These orders are in more than one currency ({$currency} and {$orderCurrency}). Import a CSV in one currency instead.");
                }
                $currency ??= $orderCurrency;
                $days[$date] ??= ['orders' => 0, 'revenue' => 0.0];
                $days[$date]['orders']++;
                $days[$date]['revenue'] += array_sum(array_map(fn ($line) => (float) ($line['total'] ?? 0), $lines));
            }
            if (count($response->json()) < 100 || $page >= (int) ($response->header('X-WP-TotalPages') ?: $page)) {
                return ['days' => $days, 'currency' => $currency, 'truncated' => false];
            }
        }

        return ['days' => $days, 'currency' => $currency, 'truncated' => true];
    }

    /** @return array<int, array{id: int, name: string}> */
    private function products(StoreConnection $store, array $query): array
    {
        $url = $this->endpoint($store, 'wc/v3/products', $query);
        $response = $this->send(fn (PendingRequest $http) => $this->authed($http, $store)->get($url), $url);
        if (in_array($response->status(), [401, 403], true)) {
            throw new StoreException('The store did not allow reading products. Connect it with a WordPress user who can manage WooCommerce (Shop manager or Administrator).', auth: true);
        }
        if ($response->status() === 404) {
            throw new StoreException('The store has no WooCommerce REST API. Check that WooCommerce is active.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw $this->failure($response, 'list products');
        }

        return array_values(array_map(fn ($product) => ['id' => (int) $product['id'], 'name' => (string) ($product['name'] ?? '')], array_filter($response->json(), fn ($product) => is_array($product) && isset($product['id']))));
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
