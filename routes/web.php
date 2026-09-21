<?php

use App\Http\Controllers\Auth\ShopifyOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Shopify OAuth install. Web group, so the session can hold the nonce.
Route::get('/auth', [ShopifyOAuthController::class, 'install'])->name('auth.install');
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback'])->name('auth.callback');
