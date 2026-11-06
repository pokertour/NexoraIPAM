<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OpenIdController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\WorkspaceAccess;
use App\Livewire\Accounts;
use App\Livewire\Inventory;
use App\Livewire\NetworkTools;
use App\Livewire\Preferences;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/connexion', [AuthController::class, 'show'])->name('login');
    Route::post('/connexion', [AuthController::class, 'login'])->middleware('throttle:30,1')->name('login.submit');
    Route::get('/connexion/verification', [AuthController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/connexion/verification', [AuthController::class, 'verifyChallenge'])->middleware('throttle:30,1')->block(10, 5)->name('two-factor.verify');
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::get('/connexion/openid', OpenIdController::class)->name('oidc.start')->block(30, 5);
        Route::get('/connexion/openid/retour', OpenIdController::class)->name('oidc.callback')->block(30, 5);
    });
});

Route::middleware(['auth', WorkspaceAccess::class])->group(function (): void {
    Route::post('/deconnexion', [AuthController::class, 'logout'])->name('logout');
    Route::get('/mot-de-passe', [AuthController::class, 'showPassword'])->name('password.change');
    Route::post('/mot-de-passe', [AuthController::class, 'password'])->middleware('throttle:6,1')->name('password.update');

    Route::get('/a-propos', AboutController::class)->name('about');
    Route::get('/profil', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profil', [ProfileController::class, 'rename'])->name('profile.rename');
    Route::post('/profil/2fa/configurer', [ProfileController::class, 'setup'])->middleware('throttle:6,1')->block(10, 5)->name('profile.two-factor.setup');
    Route::post('/profil/2fa/activer', [ProfileController::class, 'enable'])->middleware('throttle:6,1')->block(10, 5)->name('profile.two-factor.enable');
    Route::post('/profil/2fa/desactiver', [ProfileController::class, 'disable'])->middleware('throttle:6,1')->block(10, 5)->name('profile.two-factor.disable');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::livewire('/outils', NetworkTools::class)->name('tools');
    Route::livewire('/emplacements', Inventory::class)->defaults('section', 'locations')->name('locations');
    Route::livewire('/sous-reseaux', Inventory::class)->defaults('section', 'subnets')->name('subnets');
    Route::livewire('/adresses', Inventory::class)->defaults('section', 'addresses')->name('addresses');
    Route::livewire('/vlans', Inventory::class)->defaults('section', 'vlans')->name('vlans');

    Route::middleware('can:administer')->group(function (): void {
        Route::livewire('/parametres', Preferences::class)->name('preferences');
        Route::livewire('/utilisateurs', Accounts::class)->name('accounts');
    });
});
