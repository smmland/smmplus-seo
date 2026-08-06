<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FreeServiceController;
use App\Http\Controllers\Api\GiveawayController;
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

// Giveaway - verify + status are fetch/XHR calls from giveaway.twig, same CORS-origin-allowlist
// pattern as free-service above. The YouTube OAuth routes are plain browser navigations (the
// user's own address bar going to Google and back, plus Google's own redirect calling the
// callback) - they never carry a matching Origin header, so gateway.cors would wrongly reject
// them; they're protected instead by the signed `state` param (see GiveawayController).
Route::match(['GET', 'OPTIONS'], '/giveaway/config', [GiveawayController::class, 'config'])
    ->middleware('gateway.cors');
Route::match(['POST', 'OPTIONS'], '/giveaway/telegram/verify', [GiveawayController::class, 'verifyTelegram'])
    ->middleware('gateway.cors');
Route::match(['POST', 'OPTIONS'], '/giveaway/trustpilot/submit', [GiveawayController::class, 'submitTrustpilot'])
    ->middleware('gateway.cors');
Route::match(['POST', 'OPTIONS'], '/giveaway/youtube/featured/submit', [GiveawayController::class, 'submitYoutubeFeatured'])
    ->middleware('gateway.cors');
Route::match(['POST', 'OPTIONS'], '/giveaway/youtube/video/submit', [GiveawayController::class, 'submitYoutubeVideo'])
    ->middleware('gateway.cors');
Route::match(['GET', 'OPTIONS'], '/giveaway/status', [GiveawayController::class, 'status'])
    ->middleware('gateway.cors');
Route::get('/giveaway/youtube/oauth/start', [GiveawayController::class, 'youtubeOauthStart']);
Route::get('/giveaway/youtube/oauth/callback', [GiveawayController::class, 'youtubeOauthCallback']);

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
