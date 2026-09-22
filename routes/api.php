<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

// Served under /api. Every route here sits behind VerifyShopifySessionToken,
// which bootstrap/app.php adds to the whole api group — so a new route can't
// be left unprotected by forgetting it.

Route::get('/customers', [CustomerController::class, 'index']);
