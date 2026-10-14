<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\StoreConnection;
use App\Services\Pages\PageImages;
use App\Services\Pages\Stores\SandboxStore;
use Illuminate\Http\Response;

/** Serves what was published to a sandbox store, at its private address. */
class SandboxStoreController extends Controller
{
    public function home(string $token): Response
    {
        $store = $this->store($token);
        $pages = Page::query()->where('store_connection_id', $store->id)->whereNotNull('remote_url')->orderBy('name')->get();

        return response()->view('pages.sandbox', ['store' => $store, 'pages' => $pages, 'html' => null, 'page' => null])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function page(string $token, Page $page, string $slug): Response
    {
        $store = $this->store($token);
        $served = SandboxStore::served($store, $page);
        abort_unless($served && $page->store_connection_id === $store->id, 404);

        return response()->view('pages.sandbox', ['store' => $store, 'pages' => collect(), 'html' => $served->html, 'page' => $page])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }

    public function media(string $token, string $key, PageImages $images): Response
    {
        $store = $this->store($token);
        abort_unless(preg_match('/^(banner|media)-(\d+)$/', $key, $match), 404);
        $image = $images->contents(['key' => $key, 'kind' => $match[1], 'id' => (int) $match[2]], $store->workspace_id);
        abort_unless($image && isset(($store->media ?? [])[$key]), 404);

        return response($image['bytes'], 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function store(string $token): StoreConnection
    {
        $store = strlen($token) === 40 ? StoreConnection::query()->where('driver', 'sandbox')->where('api_base', $token)->first() : null;
        abort_unless($store, 404);

        return $store;
    }
}
