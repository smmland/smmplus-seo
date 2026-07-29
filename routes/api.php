<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FreeServiceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UrlsController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

// Public gateway called directly from browser JS on smm.plus - protected by CORS origin
// allowlist and per-IP/per-target rate limiting instead of a bearer token, since a secret
// token can't be kept safe in client-side code.
Route::match(['GET', 'POST', 'OPTIONS'], '/free-service/order', [FreeServiceController::class, 'order'])
    ->middleware('gateway.cors');

Route::middleware('auth.token')->group(function () {
    Route::patch('/auth/credentials', [AuthController::class, 'updateCredentials']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);

    Route::post('/sync/run', [SyncController::class, 'run']);
    Route::get('/sync/runs', [SyncController::class, 'history']);
    Route::get('/sync/status', [SyncController::class, 'status']);

    Route::get('/urls', [UrlsController::class, 'index']);
    Route::post('/urls', [UrlsController::class, 'store']);
    Route::patch('/urls/bulk-visibility', [UrlsController::class, 'bulkVisibility']);
    Route::patch('/urls/{url}', [UrlsController::class, 'update']);
    Route::delete('/urls/{url}', [UrlsController::class, 'destroy']);
});
