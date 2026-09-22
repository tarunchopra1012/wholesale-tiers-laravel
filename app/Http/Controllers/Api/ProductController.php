<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Shop;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ProductQuery;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductController extends Controller
{
    /**
     * GET /api/products?limit=20 — the calling shop's first products by
     * title, each with its first variant's price.
     */
    public function index(ProductIndexRequest $request, OAuthService $oauth): AnonymousResourceCollection
    {
        // Set by VerifyShopifySessionToken from the ID token.
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $products = (new ProductQuery(new ShopifyGraphQLClient($shop, $oauth)))
            ->first($request->integer('limit', 20));

        return ProductResource::collection($products);
    }
}
