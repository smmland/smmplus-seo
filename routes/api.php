<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AnalyticsPurchaseController;
use App\Http\Controllers\Api\FreeServiceController;
use App\Http\Controllers\Api\GiveawayController;
use App\Http\Controllers\Api\LandingServicesController;
use App\Http\Controllers\Api\ReviewsController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UrlsController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

// Lightweight first-party, SEO-focused analytics endpoint used by the global smm.plus layout.
// Browser code cannot safely hold a bearer token, so origin validation, payload limits and a
// dedicated high-enough analytics rate limit protect it instead.
Route::match(['POST', 'OPTIONS'], '/analytics/collect', [AnalyticsController::class, 'store'])
    ->middleware('analytics.cors');

// Financial values must only come from the trusted ordering backend. Unlike browser telemetry,
// this endpoint has no CORS access and authenticates the exact raw JSON body with an HMAC.
Route::post('/analytics/purchases', [AnalyticsPurchaseController::class, 'store'])
    ->middleware(['analytics.purchase.signature', 'throttle:120,1']);

// Public, read-only, open to any origin - see ReviewsController for why it skips the
// gateway.cors stack the mutating public endpoints below use.
Route::get('/reviews', [ReviewsController::class, 'index']);

// Whether to show the "leave a review" prompt on one specific page (?page=ticket_reply, etc.) -
// same read-only/open-to-any-origin reasoning as the GET above.
Route::get('/reviews/status', [ReviewsController::class, 'status']);

// schema.org aggregateRating (ratingValue + reviewCount) for pages that want the star rating in
// Google without fetching/rendering any review text - same numbers as index()'s "aggregate" key,
// just without the review list. Same read-only/open-to-any-origin reasoning as the GET above.
Route::get('/reviews/summary', [ReviewsController::class, 'summary']);

// Submitting a review is a mutating public write, unlike the GET above - same CORS-allowlist +
// abuse-protection stack as the other public POST endpoints below.
Route::match(['POST', 'OPTIONS'], '/reviews', [ReviewsController::class, 'store'])
    ->middleware('gateway.cors');

// Public, read-only cached copy of smm.plus's own retail service catalog, filtered to one
// landing page's category (?category=..., see LandingServiceCategory) - unlike the GET routes
// above this carries real pricing/service IDs, so it's restricted to the CORS allowlist
// (Security Settings) instead of open to any origin. A GET with no custom headers never
// triggers a CORS preflight, so no OPTIONS entry/middleware is needed here.
Route::get('/services', [LandingServicesController::class, 'index']);

// One already-known service by its real id (e.g. a checkout/order-confirmation page that only
// has the id, not the category slug) - same public/CORS boundary as the GET above, only ever
// returns a service currently matched by at least one active LandingServiceCategory.
Route::get('/services/{id}', [LandingServicesController::class, 'show'])->where('id', '[0-9]+');

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
