<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sitemap_index.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-{category}.xml', [SitemapController::class, 'category']);
