<?php

use App\Http\Controllers\Auth\ShopifyOAuthController;
use Illuminate\Support\Facades\Route;

// Shopify OAuth install. Web group, so the session can hold the nonce.
Route::get('/auth', [ShopifyOAuthController::class, 'install'])->name('auth.install');
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback'])->name('auth.callback');

// The React app, on every path React Router handles, so a reload on
// /settings gets the page rather than a 404. Kept last so /auth matches
// first. /api is excluded so an unknown API path still answers a JSON 404
// instead of this HTML.
Route::view('/{path?}', 'app')->where('path', '(?!api(/|$)).*');
