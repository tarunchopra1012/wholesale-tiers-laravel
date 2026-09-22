<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerIndexRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Shop;
use App\Services\Shopify\CustomerQuery;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerController extends Controller
{
    /**
     * GET /api/customers?tier=…&after=… — one page of the calling shop's
     * customers, optionally only one tier.
     */
    public function index(CustomerIndexRequest $request, OAuthService $oauth): AnonymousResourceCollection
    {
        // Set by VerifyShopifySessionToken from the ID token. Not
        // $request->shop, which would read ?shop= from the URL.
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $page = (new CustomerQuery(new ShopifyGraphQLClient($shop, $oauth)))
            ->page($request->validated('tier'), $request->validated('after'));

        return CustomerResource::collection($page['customers'])->additional([
            'page_info' => [
                'has_next_page' => $page['pageInfo']['hasNextPage'],
                'end_cursor' => $page['pageInfo']['endCursor'],
            ],
        ]);
    }
}
