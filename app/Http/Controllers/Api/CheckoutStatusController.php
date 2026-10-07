<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutStatusResource;
use App\Models\Shop;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\TierDiscountSync;
use App\Support\TierDiscountConfig;
use Illuminate\Http\Request;

final class CheckoutStatusController extends Controller
{
    /**
     * GET /api/checkout-status — whether the calling shop's tiers are live
     * at checkout, and when they were last sent there.
     *
     * Apart from GET /api/tiers because this one asks Shopify: the tiers
     * themselves still load when Shopify is slow or down.
     */
    public function show(Request $request, OAuthService $oauth): CheckoutStatusResource
    {
        // Set by VerifyShopifySessionToken from the ID token.
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $state = (new TierDiscountSync(new ShopifyGraphQLClient($shop, $oauth), new TierDiscountConfig))
            ->status($shop);

        return new CheckoutStatusResource([
            'state' => $state,
            'syncedAt' => $shop->tier_discount_synced_at,
        ]);
    }
}
