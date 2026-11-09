<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OpenIdController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\WorkspaceAccess;
use App\Livewire\Accounts;
use App\Livewire\Alerts;
use App\Livewire\ApiTokens;
use App\Livewire\AuditTrail;
use App\Livewire\DeviceDetail;
use App\Livewire\Inventory;
use App\Livewire\Metadata;
use App\Livewire\NetworkDevices;
use App\Livewire\NetworkTools;
use App\Livewire\NetworkTopology;
use App\Livewire\Preferences;
use App\Livewire\Racks;
use App\Livewire\ScanHistory;
use App\Livewire\WallOutlets;
use Illuminate\Support\Facades\Route;

Route::post('/locale', LocaleController::class)->name('locale.update');

Route::middleware('guest')->group(function (): void {
    Route::get('/auth', [AuthController::class, 'show'])->name('login');
    Route::post('/auth', [AuthController::class, 'login'])->middleware('throttle:30,1')->name('login.submit');
    Route::get('/auth/two-factor', [AuthController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/auth/two-factor', [AuthController::class, 'verifyChallenge'])->middleware('throttle:30,1')->block(10, 5)->name('two-factor.verify');
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::get('/auth/openid', OpenIdController::class)->name('oidc.start')->block(30, 5);
        Route::get('/auth/openid/callback', OpenIdController::class)->name('oidc.callback')->block(30, 5);
    });
});

Route::middleware(['auth', WorkspaceAccess::class])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/auth/password', [AuthController::class, 'showPassword'])->name('password.change');
    Route::post('/auth/password', [AuthController::class, 'password'])->middleware('throttle:6,1')->name('password.update');

    Route::get('/about', AboutController::class)->name('about');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profile', [ProfileController::class, 'rename'])->name('profile.rename');
    Route::post('/profile/email', [ProfileController::class, 'requestEmail'])->middleware('throttle:6,1')->name('profile.email');
    Route::get('/profile/email/verify/{user}', [ProfileController::class, 'verifyEmail'])->middleware('throttle:12,1')->name('profile.email.verify');
    Route::post('/profile/email/cancel', [ProfileController::class, 'cancelEmail'])->name('profile.email.cancel');
    Route::post('/profile/two-factor/setup', [ProfileController::class, 'setup'])->middleware('throttle:6,1')->block(10, 5)->name('profile.two-factor.setup');
    Route::post('/profile/two-factor/enable', [ProfileController::class, 'enable'])->middleware('throttle:6,1')->block(10, 5)->name('profile.two-factor.enable');
    Route::post('/profile/two-factor/disable', [ProfileController::class, 'disable'])->middleware('throttle:6,1')->block(10, 5)->name('profile.two-factor.disable');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::livewire('/tools', NetworkTools::class)->name('tools');
    Route::livewire('/alerts', Alerts::class)->name('alerts');
    Route::livewire('/scans', ScanHistory::class)->name('scans');
    Route::livewire('/devices', NetworkDevices::class)->name('devices');
    Route::livewire('/devices/{device}', DeviceDetail::class)->name('devices.show');
    Route::livewire('/racks', Racks::class)->name('racks');
    Route::livewire('/outlets', WallOutlets::class)->name('outlets');
    Route::livewire('/topology', NetworkTopology::class)->name('topology');
    Route::livewire('/locations', Inventory::class)->defaults('section', 'locations')->name('locations');
    Route::livewire('/subnets', Inventory::class)->defaults('section', 'subnets')->name('subnets');
    Route::livewire('/addresses', Inventory::class)->defaults('section', 'addresses')->name('addresses');
    Route::livewire('/vlans', Inventory::class)->defaults('section', 'vlans')->name('vlans');

    Route::middleware('can:administer')->group(function (): void {
        Route::livewire('/settings', Preferences::class)->name('preferences');
        Route::livewire('/users', Accounts::class)->name('accounts');
        Route::livewire('/audit', AuditTrail::class)->name('audit');
        Route::livewire('/api-tokens', ApiTokens::class)->name('api-tokens');
        Route::livewire('/metadata', Metadata::class)->name('metadata');
    });
});
