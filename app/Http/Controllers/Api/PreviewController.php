<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewRequest;
use App\Http\Resources\PreviewResource;
use App\Models\Shop;
use App\Models\TierSetting;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ProductQuery;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Support\TierCalculator;

final class PreviewController extends Controller
{
    /**
     * GET /api/preview?product_id=… — a product's base price, and what each
     * of the calling shop's tiers would pay for it.
     *
     * The price is read from Shopify here, never taken from the browser, so
     * the preview always matches the store.
     */
    public function show(PreviewRequest $request, OAuthService $oauth, TierCalculator $calculator): PreviewResource
    {
        // Set by VerifyShopifySessionToken from the ID token.
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $product = (new ProductQuery(new ShopifyGraphQLClient($shop, $oauth)))
            ->find($request->validated('product_id'));

        if ($product === null) {
            abort(404, 'This store has no product with that ID.');
        }

        $tiers = $shop->tierSettings()->orderBy('tag')->get();

        return new PreviewResource([
            'product' => $product,
            'tiers' => $tiers->map(fn (TierSetting $tier): array => [
                'tier' => $tier,
                'finalPriceCents' => $calculator->calculate($product['priceCents'], $tier),
            ])->all(),
        ]);
    }
}
