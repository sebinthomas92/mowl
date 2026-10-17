<?php

namespace App\Http\Controllers;

use App\Services\Pages\StoreException;
use App\Services\Shopify\ShopifyAuthorization;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopifyConnectionController extends Controller
{
    public function callback(Request $request, ShopifyAuthorization $authorization): RedirectResponse
    {
        try {
            $store = $authorization->finish($request);
        } catch (LockTimeoutException) {
            return redirect()->route('workspace.settings')->with('shopify_error', 'This store is already being connected. Try again shortly.');
        } catch (StoreException $exception) {
            return redirect()->route('workspace.settings')->with('shopify_error', $exception->getMessage());
        }

        return redirect()->to(route('workspace.settings').'#connections')->with('shopify_connected', $store->name.' is connected.');
    }
}
