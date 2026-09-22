<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wholesale Tiers</title>

    {{-- The public client ID, which App Bridge needs to start. Never the
         secret, and never an access token: neither belongs in a page. --}}
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
</head>
<body>
    {{-- Placeholder until the React shell. The page exists so App Bridge
         loads inside the admin and can issue ID tokens. --}}
    <p>Wholesale Tiers</p>
</body>
</html>
