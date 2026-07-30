<?php

use App\Http\Controllers\BlogContentAssetController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => config('app.name'), 'status' => 'ok']));

Route::get('/sitemap_index.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-{category}.xml', [SitemapController::class, 'category']);

Route::get('/blog-content/{path}', [BlogContentAssetController::class, 'show'])->where('path', '.*');
