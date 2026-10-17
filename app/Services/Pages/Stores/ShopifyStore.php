<?php

namespace App\Services\Pages\Stores;

use App\Models\Page;
use App\Models\StoreConnection;
use App\Services\Pages\PageImages;
use App\Services\Pages\StoreException;
use App\Services\Shopify\ShopifyApi;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Publishes ordinary Shopify online-store pages. No theme-file access or checkout changes. */
class ShopifyStore implements StoreClient
{
    public function __construct(private ShopifyApi $api, private PageImages $images) {}

    public function check(StoreConnection $store): array
    {
        $data = $this->api->query($store, 'query MarketingOwlConnection { shop { primaryDomain { url } } currentAppInstallation { accessScopes { handle } } }');
        if ($this->api->missingScopes(array_column($data['currentAppInstallation']['accessScopes'] ?? [], 'handle'))) {
            throw new StoreException('Shopify needs permission to publish pages and upload images. Reconnect the store.', auth: true);
        }
        $url = $data['shop']['primaryDomain']['url'] ?? null;
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new StoreException('Shopify did not return a secure storefront address.');
        }

        return ['api_base' => rtrim($url, '/'), 'has_woocommerce' => false];
    }

    public function uploadImage(StoreConnection $store, array $ref, int $workspaceId): array
    {
        $image = $this->images->contents($ref, $workspaceId);
        if (! $image) {
            throw new StoreException('The page image could not be read. Choose another image, then publish again.');
        }
        $pending = ($store->fresh()->media ?? [])[$ref['key']]['id'] ?? null;
        if (! $pending) {
            $data = $this->api->query($store, 'mutation MarketingOwlStage($input: [StagedUploadInput!]!) { stagedUploadsCreate(input: $input) { stagedTargets { url resourceUrl parameters { name value } } userErrors { message } } }', [
                'input' => [['filename' => $image['filename'], 'mimeType' => $image['mime'], 'httpMethod' => 'POST', 'resource' => 'IMAGE', 'fileSize' => (string) strlen($image['bytes'])]],
            ]);
            $result = $this->result($data, 'stagedUploadsCreate');
            $target = $result['stagedTargets'][0] ?? [];
            $url = $target['url'] ?? '';
            if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || empty($target['resourceUrl'])) {
                throw new StoreException('Shopify did not return an image upload destination.');
            }
            $binding = PublicAddress::binding($url);
            try {
                $response = Http::timeout(config('pages.timeout_seconds'))
                    ->withOptions(['allow_redirects' => false] + ($binding ? ['curl' => [CURLOPT_RESOLVE => [$binding]]] : []))
                    ->attach('file', $image['bytes'], $image['filename'], ['Content-Type' => $image['mime']])
                    ->post($url, array_column($target['parameters'] ?? [], 'value', 'name'));
            } catch (ConnectionException) {
                throw new StoreException('The Shopify image upload was interrupted. Try publishing again.');
            }
            if (! $response->successful()) {
                throw new StoreException('Shopify could not accept the image upload. Try publishing again.');
            }
            $data = $this->api->query($store, 'mutation MarketingOwlFile($files: [FileCreateInput!]!) { fileCreate(files: $files) { files { id fileStatus ... on MediaImage { image { url } } } userErrors { message } } }', [
                'files' => [['originalSource' => $target['resourceUrl'], 'contentType' => 'IMAGE', 'alt' => $ref['alt'] ?? '']],
            ]);
            $file = $this->result($data, 'fileCreate')['files'][0] ?? [];
            $pending = $file['id'] ?? null;
            if (! $pending) {
                throw new StoreException('Shopify did not return an uploaded image.');
            }
            // Shopify processes files asynchronously. Retain the ID before polling so a retry reuses it.
            DB::transaction(function () use ($store, $ref, $pending): void {
                $locked = StoreConnection::query()->lockForUpdate()->findOrFail($store->id);
                $locked->update(['media' => [$ref['key'] => ['id' => $pending]] + ($locked->media ?? [])]);
            });
        }
        for ($check = 0; $check < 3; $check++) {
            $data = $this->api->query($store, 'query MarketingOwlFileStatus($id: ID!) { node(id: $id) { ... on MediaImage { id fileStatus image { url } } } }', ['id' => $pending]);
            $file = $data['node'] ?? [];
            if (($file['fileStatus'] ?? '') === 'READY' && filled($file['image']['url'] ?? null)) {
                return ['id' => $pending, 'url' => $file['image']['url']];
            }
            if (! $file || ($file['fileStatus'] ?? '') === 'FAILED') {
                throw new StoreException('Shopify could not process this image. Choose another image and publish again.');
            }
            if ($check < 2) {
                usleep(250_000);
            }
        }
        throw new StoreException('Shopify is still processing the image. Retry publishing shortly; the uploaded image will be reused.');
    }

    public function putPage(StoreConnection $store, Page $page, ?string $remoteId, string $title, string $html): array
    {
        if ($remoteId) {
            $existing = $this->api->query($store, 'query MarketingOwlPage($id: ID!) { page(id: $id) { id } }', ['id' => $remoteId]);
            if (empty($existing['page'])) {
                $remoteId = null;
            }
        }
        if (! $remoteId) {
            // A lost create response must not create a duplicate on retry. Only reclaim our own page.
            $found = $this->api->query($store, 'query MarketingOwlRecoverPage($query: String!) { pages(first: 10, query: $query) { nodes { id handle body } } }', ['query' => 'handle:'.$page->slug]);
            foreach ($found['pages']['nodes'] ?? [] as $candidate) {
                if ($candidate['handle'] === $page->slug && preg_match('/data-mowl-page=["\']'.(int) $page->id.'["\']/', $candidate['body'] ?? '')) {
                    $remoteId = $candidate['id'];
                    break;
                }
                if ($candidate['handle'] === $page->slug) {
                    throw new StoreException('This address is already used by another Shopify page. Choose a different page address.');
                }
            }
        }
        $input = ['title' => $title, 'handle' => $page->slug, 'body' => $html, 'isPublished' => true];
        if ($remoteId) {
            $data = $this->api->query($store, 'mutation MarketingOwlPageUpdate($id: ID!, $page: PageUpdateInput!) { pageUpdate(id: $id, page: $page) { page { id handle } userErrors { message } } }', ['id' => $remoteId, 'page' => $input]);
            $result = $this->result($data, 'pageUpdate');
        } else {
            $data = $this->api->query($store, 'mutation MarketingOwlPageCreate($page: PageCreateInput!) { pageCreate(page: $page) { page { id handle } userErrors { message } } }', ['page' => $input]);
            $result = $this->result($data, 'pageCreate');
        }
        $remote = $result['page'] ?? [];
        if (empty($remote['id']) || empty($remote['handle'])) {
            throw new StoreException('Shopify returned an incomplete page address.', unsure: true);
        }

        return ['id' => $remote['id'], 'url' => rtrim($store->api_base, '/').'/pages/'.rawurlencode($remote['handle'])];
    }

    public function fetchPublic(StoreConnection $store, Page $page, string $url): string
    {
        $current = $url.(str_contains($url, '?') ? '&' : '?').'mowl_verify='.time();
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $binding = PublicAddress::binding($current);
            try {
                // Public verification must never send an Admin API token, even on a redirect.
                $response = Http::accept('text/html')->timeout(config('pages.timeout_seconds'))
                    ->withOptions(['allow_redirects' => false] + ($binding ? ['curl' => [CURLOPT_RESOLVE => [$binding]]] : []))->get($current);
            } catch (ConnectionException) {
                throw new StoreException('The Shopify page address could not be reached.');
            }
            if (! $response->redirect()) {
                if (! $response->successful()) {
                    throw new StoreException('The Shopify page address returned HTTP '.$response->status().'.');
                }

                return substr($response->body(), 0, 4 * 1024 * 1024);
            }
            if (! $response->header('Location')) {
                break;
            }
            $current = (string) UriResolver::resolve(new Uri($current), new Uri($response->header('Location')));
        }
        throw new StoreException('The Shopify page address redirects too many times.');
    }

    private function result(array $data, string $operation): array
    {
        $result = $data[$operation] ?? [];
        if (! $result || ! empty($result['userErrors'])) {
            throw new StoreException('Shopify could not save this content. Check the page details and image, then try again.');
        }

        return $result;
    }
}
