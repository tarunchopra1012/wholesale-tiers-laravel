<?php

use App\Http\Controllers\Auth\ShopifyOAuthController;
use Illuminate\Support\Facades\Route;

// The page Shopify loads inside the admin iframe. For now it only loads
// App Bridge, so the embedded app can get ID tokens for /api calls.
Route::view('/', 'app');

// Shopify OAuth install. Web group, so the session can hold the nonce.
Route::get('/auth', [ShopifyOAuthController::class, 'install'])->name('auth.install');
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback'])->name('auth.callback');
