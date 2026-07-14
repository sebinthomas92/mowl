<?php

use App\Http\Controllers\BannerDeliveryController;
use App\Http\Controllers\BannerGenerationController;
use App\Http\Controllers\CampaignJobController;
use App\Http\Controllers\CampaignPackDeliveryController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\BrandIndex;
use App\Livewire\CampaignPackIndex;
use App\Livewire\CampaignWorkspace;
use App\Livewire\ConciergeIndex;
use App\Livewire\ProductIndex;
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

Route::middleware('auth')->group(function (): void {
    Route::post('/workspaces/{workspace}/select', function (Workspace $workspace, Request $request) {
        abort_unless($request->user()->workspaces()->whereKey($workspace->id)->exists(), 404);
        $request->session()->put('current_workspace_id', $workspace->id);

        return back();
    })->name('workspaces.select');
    Route::get('/brands', BrandIndex::class)->name('brands.index');
    Route::get('/products', ProductIndex::class)->name('products.index');
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
