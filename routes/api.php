<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PreviewController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TierSettingController;
use Illuminate\Support\Facades\Route;

// Served under /api. Every route here sits behind VerifyShopifySessionToken,
// which bootstrap/app.php adds to the whole api group — so a new route can't
// be left unprotected by forgetting it.

Route::get('/customers', [CustomerController::class, 'index']);

Route::get('/tiers', [TierSettingController::class, 'index']);
Route::post('/tiers', [TierSettingController::class, 'store']);
Route::put('/tiers', [TierSettingController::class, 'update']);
Route::delete('/tiers/{id}', [TierSettingController::class, 'destroy'])->whereNumber('id');

Route::get('/products', [ProductController::class, 'index']);

Route::get('/preview', [PreviewController::class, 'show']);
