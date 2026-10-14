<?php

use App\Http\Controllers\BannerDeliveryController;
use App\Http\Controllers\BannerGenerationController;
use App\Http\Controllers\CampaignJobController;
use App\Http\Controllers\CampaignPackDeliveryController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\ClientReviewController;
use App\Http\Controllers\ProductHubDeliveryController;
use App\Http\Controllers\SandboxStoreController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\BrandEditor;
use App\Livewire\BrandIndex;
use App\Livewire\CampaignIndex;
use App\Livewire\CampaignPackIndex;
use App\Livewire\CampaignWorkspace;
use App\Livewire\ClientPortal;
use App\Livewire\ClientReview;
use App\Livewire\ConciergeIndex;
use App\Livewire\LibraryIndex;
use App\Livewire\ProductCreate;
use App\Livewire\ProductHub;
use App\Livewire\ProductIndex;
use App\Livewire\ReviewQueue;
use App\Livewire\TeamIndex;
use App\Livewire\UsageIndex;
use App\Livewire\WorkspaceSettings;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/campaign-packs');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::get('/invitations/{token}', [WorkspaceInvitationController::class, 'accept'])
    ->middleware('throttle:12,1')
    ->name('invitations.accept');
Route::get('/shared/campaign-packs/{token}', [CampaignPackDeliveryController::class, 'share'])->name('campaign-packs.share');
Route::get('/client/{token}', ClientReview::class)->middleware('throttle:60,1')->name('client.review');
Route::get('/client/{token}/banners/{contentItem}', [ClientReviewController::class, 'banner'])->middleware('throttle:120,1')->name('client.review-banner');
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('/sandbox-store/{token}', [SandboxStoreController::class, 'home'])->name('sandbox-store.home');
    Route::get('/sandbox-store/{token}/pages/{page}/{slug}', [SandboxStoreController::class, 'page'])->name('sandbox-store.page');
    Route::get('/sandbox-store/{token}/media/{key}', [SandboxStoreController::class, 'media'])->name('sandbox-store.media');
});
Route::get('/shared/products/{token}', [ProductHubDeliveryController::class, 'share'])->name('product-hubs.share');
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
    Route::get('/team', TeamIndex::class)->name('team.index');
    Route::get('/usage', UsageIndex::class)->name('usage.index');
    Route::get('/settings', WorkspaceSettings::class)->name('workspace.settings');
    Route::get('/concierge', ConciergeIndex::class)->name('concierge.index');
    Route::get('/portal', ClientPortal::class)->name('portal.index');
    Route::get('/portal/items/{contentItem}/image', [ClientPortalController::class, 'image'])->name('portal.image');
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

Route::get('/internal/campaign-jobs/recover', [CampaignJobController::class, 'recover'])
    ->middleware('throttle:6,1')
    ->name('campaign-jobs.recover');
Route::get('/internal/banner-creatives/recover', [BannerGenerationController::class, 'recover'])
    ->middleware('throttle:6,1')
    ->name('banner-creatives.recover');
