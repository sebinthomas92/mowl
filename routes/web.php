<?php

use App\Http\Controllers\CampaignJobController;
use App\Http\Controllers\MediaAssetDownloadController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\BrandIndex;
use App\Livewire\CampaignPackIndex;
use App\Livewire\CampaignWorkspace;
use App\Livewire\ProductIndex;
use App\Livewire\TeamIndex;
use App\Livewire\UsageIndex;
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

Route::middleware('auth')->group(function (): void {
    Route::get('/brands', BrandIndex::class)->name('brands.index');
    Route::get('/products', ProductIndex::class)->name('products.index');
    Route::get('/campaign-packs', CampaignPackIndex::class)->name('campaign-packs.index');
    Route::get('/campaign-packs/create', CampaignWorkspace::class)->name('campaign-packs.create');
    Route::get('/campaign-packs/{pack}', CampaignWorkspace::class)->name('campaign-packs.show');
    Route::get('/team', TeamIndex::class)->name('team.index');
    Route::get('/usage', UsageIndex::class)->name('usage.index');
    Route::get('/media-assets/{mediaAsset}/download', MediaAssetDownloadController::class)->name('media-assets.download');
    Route::post('/internal/campaign-jobs/{generationJob}/process', [CampaignJobController::class, 'process'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('campaign-jobs.process');

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
