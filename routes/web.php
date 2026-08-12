<?php

use App\Http\Controllers\BlogContentAssetController;
use App\Http\Controllers\EditorAssetController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => config('app.name'), 'status' => 'ok']));

Route::get('/sitemap_index.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-{category}.xml', [SitemapController::class, 'category']);

Route::get('/blog-content/{path}', [BlogContentAssetController::class, 'show'])->where('path', '.*');
Route::get('/editor-assets/{path}', [EditorAssetController::class, 'show'])->where('path', '.*');

// Reuses the same disk-streaming controller as /blog-content - it's generic over any path on the
// 'public' disk, not actually blog-specific - for Telegram post images (TelegramImageAiService,
// TelegramPostGeneratorService, TelegramQueue's "New message" upload), for the same no-symlink
// reason documented on that controller.
Route::get('/telegram-images/{path}', [BlogContentAssetController::class, 'show'])->where('path', '.*');

// Same reuse, for review avatars (ReviewResource, Review::avatarUrl()).
Route::get('/review-avatars/{path}', [BlogContentAssetController::class, 'show'])->where('path', '.*');
