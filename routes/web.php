<?php

use App\Http\Controllers\CampaignJobController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\BrandIndex;
use App\Livewire\CampaignPackIndex;
use App\Livewire\CampaignWorkspace;
use App\Livewire\ProductIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/campaign-packs');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/brands', BrandIndex::class)->name('brands.index');
    Route::get('/products', ProductIndex::class)->name('products.index');
    Route::get('/campaign-packs', CampaignPackIndex::class)->name('campaign-packs.index');
    Route::get('/campaign-packs/create', CampaignWorkspace::class)->name('campaign-packs.create');
    Route::get('/campaign-packs/{pack}', CampaignWorkspace::class)->name('campaign-packs.show');
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
