<?php

use App\Http\Controllers\BannerDeliveryController;
use App\Http\Controllers\BannerGenerationController;
use App\Http\Controllers\CampaignJobController;
use App\Http\Controllers\CampaignPackDeliveryController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\ClientReviewController;
use App\Http\Controllers\ProductHubDeliveryController;
use App\Http\Controllers\SandboxStoreController;
use App\Http\Controllers\StoreApprovalController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WorkflowDownloadController;
use App\Http\Controllers\WorkflowPreviewController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Middleware\NoIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\BillingIndex;
use App\Livewire\BrandEditor;
use App\Livewire\BrandIndex;
use App\Livewire\CampaignIndex;
use App\Livewire\CampaignPackIndex;
use App\Livewire\CampaignWorkspace;
use App\Livewire\ClientLink;
use App\Livewire\ClientPortal;
use App\Livewire\ConciergeIndex;
use App\Livewire\HelpIndex;
use App\Livewire\LibraryIndex;
use App\Livewire\ProductCreate;
use App\Livewire\ProductHub;
use App\Livewire\ProductIndex;
use App\Livewire\ReviewQueue;
use App\Livewire\TeamIndex;
use App\Livewire\UsageIndex;
use App\Livewire\WorkflowIndex;
use App\Livewire\WorkspaceSettings;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/products');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::get('/invitations/{token}', [WorkspaceInvitationController::class, 'accept'])
    ->middleware('throttle:12,1')
    ->name('invitations.accept');
Route::get('/shared/campaign-packs/{token}', [CampaignPackDeliveryController::class, 'share'])->name('campaign-packs.share');
// Client links, no sign-in. /shared/products is the client link from the Client hub tab; /client is an older named review link.
// Both open the same page: work waiting for the client's decision, then the client hub.
Route::get('/client/{token}', ClientLink::class)->middleware(['throttle:60,1', NoIndex::class])->name('client.review');
Route::get('/client/{token}/banners/{contentItem}', [ClientReviewController::class, 'banner'])->middleware('throttle:120,1')->name('client.review-banner');
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('/client/{token}/hub/google-ads/{campaignType}.csv', [ProductHubDeliveryController::class, 'reviewLinkExport'])->name('client.hub-export');
    Route::get('/client/{token}/hub/media/{mediaAsset}', [ProductHubDeliveryController::class, 'reviewLinkMedia'])->name('client.hub-media');
    Route::get('/client/{token}/hub/creatives/{bannerCreative}', [ProductHubDeliveryController::class, 'reviewLinkCreative'])->name('client.hub-creative');
});
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('/sandbox-store/{token}', [SandboxStoreController::class, 'home'])->name('sandbox-store.home');
    Route::get('/sandbox-store/{token}/pages/{page}/{slug}', [SandboxStoreController::class, 'page'])->name('sandbox-store.page');
    Route::get('/sandbox-store/{token}/media/{key}', [SandboxStoreController::class, 'media'])->name('sandbox-store.media');
});
Route::get('/shared/products/{token}', ClientLink::class)->middleware(['throttle:60,1', NoIndex::class])->name('product-hubs.share');
Route::get('/shared/products/{token}/items/{contentItem}/image', [ClientReviewController::class, 'sharedBanner'])->middleware('throttle:120,1')->name('product-hubs.shared-item-image');
Route::get('/shared/products/{token}/google-ads/{campaignType}.csv', [ProductHubDeliveryController::class, 'sharedExport'])->name('product-hubs.shared-export');
Route::get('/shared/products/{token}/media/{mediaAsset}', [ProductHubDeliveryController::class, 'sharedMedia'])->name('product-hubs.shared-media');
Route::get('/shared/products/{token}/creatives/{bannerCreative}', [ProductHubDeliveryController::class, 'sharedCreative'])->name('product-hubs.shared-creative');

Route::middleware(['auth', 'workspace.role'])->group(function (): void {
    Route::post('/workspaces/{workspace}/select', function (Workspace $workspace, Request $request) {
        abort_unless($request->user()->workspaces()->whereKey($workspace->id)->exists(), 404);
        $request->session()->put('current_workspace_id', $workspace->id);

        return back();
    })->name('workspaces.select');
    Route::get('/brands', BrandIndex::class)->name('brands.index');
    Route::get('/brands/new', BrandEditor::class)->name('brands.create');
    Route::get('/brands/{brand}/edit', BrandEditor::class)->name('brands.edit');
    Route::get('/products', ProductIndex::class)->name('products.index');
    Route::get('/products/new', ProductCreate::class)->name('products.create');
    Route::get('/products/{product}', ProductHub::class)->name('products.show');
    Route::get('/products/{product}/google-ads/{campaignType}.csv', [ProductHubDeliveryController::class, 'export'])->name('products.hub-export');
    Route::get('/products/{product}/media/{mediaAsset}', [ProductHubDeliveryController::class, 'media'])->name('products.hub-media');
    Route::get('/products/{product}/creatives/{bannerCreative}', [ProductHubDeliveryController::class, 'creative'])->name('products.hub-creative');
    Route::get('/library', LibraryIndex::class)->name('library.index');
    Route::get('/review', ReviewQueue::class)->name('review.index');
    Route::get('/campaigns', CampaignIndex::class)->name('campaigns.index');
    Route::get('/campaign-packs', CampaignPackIndex::class)->name('campaign-packs.index');
    Route::get('/campaign-packs/create', CampaignWorkspace::class)->name('campaign-packs.create');
    Route::get('/campaign-packs/{pack}', CampaignWorkspace::class)->name('campaign-packs.show');
    Route::get('/campaign-packs/{pack}/versions/{version}/export/{format}', [CampaignPackDeliveryController::class, 'export'])->name('campaign-packs.export');
    Route::get('/campaign-packs/{pack}/banners/{bannerCreative}/image', [BannerDeliveryController::class, 'image'])->name('campaign-banners.image');
    Route::get('/campaign-packs/{pack}/banners/{bannerCreative}/download', [BannerDeliveryController::class, 'download'])->name('campaign-banners.download');
    Route::get('/workflows', WorkflowIndex::class)->name('workflows.index');
    Route::get('/workflows/{workflow}/download', WorkflowDownloadController::class)->where('workflow', '[a-z0-9-]+')->name('workflows.download');
    Route::get('/workflows/prompts/{prompt}/{kind}', WorkflowPreviewController::class)->whereNumber('prompt')->whereIn('kind', ['video', 'poster'])->name('workflows.preview');
    Route::get('/help', HelpIndex::class)->name('help.index');
    Route::get('/team', TeamIndex::class)->name('team.index');
    Route::get('/usage', UsageIndex::class)->name('usage.index');
    Route::get('/billing', BillingIndex::class)->name('billing.index');
    Route::get('/settings', WorkspaceSettings::class)->name('workspace.settings');
    // WordPress sends the owner back here with a new application password; the throttle limits guessing.
    Route::get('/settings/stores/wordpress', StoreApprovalController::class)->middleware(['throttle:20,1', NoIndex::class])->name('stores.wordpress.return');
    Route::get('/concierge', ConciergeIndex::class)->name('concierge.index');
    Route::get('/portal', ClientPortal::class)->name('portal.index');
    Route::get('/portal/items/{contentItem}/image', [ClientPortalController::class, 'image'])->name('portal.image');
    // {productId}, not {product}: the role middleware turns clients away from every {product} route.
    Route::get('/portal/products/{productId}/google-ads/{campaignType}.csv', [ProductHubDeliveryController::class, 'portalExport'])->whereNumber('productId')->name('portal.hub-export');
    Route::get('/portal/products/{productId}/media/{mediaAsset}', [ProductHubDeliveryController::class, 'portalMedia'])->whereNumber('productId')->name('portal.hub-media');
    Route::get('/portal/products/{productId}/creatives/{bannerCreative}', [ProductHubDeliveryController::class, 'portalCreative'])->whereNumber('productId')->name('portal.hub-creative');
    Route::post('/internal/campaign-jobs/{generationJob}/process', [CampaignJobController::class, 'process'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('campaign-jobs.process');
    Route::post('/internal/banner-creatives/{bannerCreative}/process', [BannerGenerationController::class, 'process'])
        ->middleware(['signed', 'throttle:12,1'])
        ->name('banner-creatives.process');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});

Route::post('/stripe/webhook', StripeWebhookController::class)->middleware('throttle:240,1')->name('stripe.webhook');

Route::get('/internal/campaign-jobs/recover', [CampaignJobController::class, 'recover'])
    ->middleware('throttle:6,1')
    ->name('campaign-jobs.recover');
Route::get('/internal/banner-creatives/recover', [BannerGenerationController::class, 'recover'])
    ->middleware('throttle:6,1')
    ->name('banner-creatives.recover');
