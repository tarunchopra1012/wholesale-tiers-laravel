<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | App credentials
    |--------------------------------------------------------------------------
    |
    | From the app's page in the Partner Dashboard. Shopify's docs call these
    | the "client ID" and "client secret". The secret signs the OAuth callback
    | and must never leave the server.
    |
    */

    'api_key' => env('SHOPIFY_API_KEY', ''),

    'api_secret' => env('SHOPIFY_API_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Access scopes
    |--------------------------------------------------------------------------
    |
    | Comma-separated, sent as-is on the authorize URL.
    |
    */

    'scopes' => env('SHOPIFY_SCOPES', ''),

    /*
    |--------------------------------------------------------------------------
    | Public app URL
    |--------------------------------------------------------------------------
    |
    | The HTTPS address Shopify reaches this app on — the tunnel URL in
    | development. The OAuth redirect URI is built from this, so it must match
    | the app URL set in the Partner Dashboard exactly.
    |
    */

    'app_url' => env('SHOPIFY_APP_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Admin API version
    |--------------------------------------------------------------------------
    |
    | Pinned in code, not read from env. Changing it changes the response
    | shapes every query depends on, so it should be a reviewed commit.
    |
    */

    'api_version' => '2026-07',

];
