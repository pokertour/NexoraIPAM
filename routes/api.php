<?php

use App\Http\Controllers\Api\V1\IpAddressController;
use App\Http\Controllers\Api\V1\ReadController;
use App\Http\Controllers\Api\V1\SubnetController;
use App\Http\Controllers\Api\V1\VlanController;
use App\Http\Middleware\EnsureApiAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', EnsureApiAccess::class, 'throttle:api'])->group(function (): void {
    Route::get('me', [ReadController::class, 'me'])->name('me');
    Route::get('search', [ReadController::class, 'search'])->name('search');
    Route::get('locations', [ReadController::class, 'locations'])->name('locations.index');
    Route::get('locations/{location}', [ReadController::class, 'location'])->name('locations.show');
    Route::get('devices', [ReadController::class, 'devices'])->name('devices.index');
    Route::get('devices/{device}', [ReadController::class, 'device'])->name('devices.show');

    Route::get('subnets/{subnet}/addresses', [SubnetController::class, 'addresses'])->name('subnets.addresses');
    Route::post('subnets/{subnet}/next-free', [SubnetController::class, 'nextFree'])->name('subnets.next-free');
    Route::apiResource('subnets', SubnetController::class);
    Route::apiResource('addresses', IpAddressController::class)->parameters(['addresses' => 'address']);
    Route::apiResource('vlans', VlanController::class);
});
