<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wholesale Tiers</title>

    {{-- The public client ID, which App Bridge needs to start. Never the
         secret, and never an access token: neither belongs in a page. --}}
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">

    {{-- First script, and not async, defer or a module: App Bridge wraps
         fetch() so /api calls carry the ID token, and that has to be in
         place before any of our code runs. --}}
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    {{-- Polaris CSS arrives through main.jsx's import, not a <link> here. --}}
    @viteReactRefresh
    @vite('resources/js/main.jsx')
</head>
<body>
    <div id="root"></div>
</body>
</html>
