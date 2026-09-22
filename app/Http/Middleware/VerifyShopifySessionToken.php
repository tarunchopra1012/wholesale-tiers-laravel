<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Shopify\InvalidSessionTokenException;
use App\Services\Shopify\SessionTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api request must carry the ID token App Bridge attaches, and is
 * answered for the shop named inside that token — nothing else.
 *
 * The shop is put on $request->attributes; controllers read it back with
 * $request->attributes->get('shop'). Not $request->shop: that magic
 * property reads the query string and body first, so ?shop=… would win.
 */
final class VerifyShopifySessionToken
{
    /**
     * Tells App Bridge to fetch a fresh ID token and retry the request.
     */
    private const RETRY_HEADER = ['X-Shopify-Retry-Invalid-Session-Request' => '1'];

    public function __construct(private readonly SessionTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            $this->reject('No bearer token.', retry: true);
        }

        try {
            $domain = $this->verifier->verify($token);
        } catch (InvalidSessionTokenException $e) {
            $this->reject($e->getMessage(), retry: true);
        }

        $shop = Shop::where('shop_domain', $domain->value)->first();

        // No retry header here: the token was fine, and a fresh one won't
        // install the app. A retry would only fail the same way twice.
        if ($shop === null || $shop->uninstalled_at !== null) {
            $this->reject("{$domain} has not installed the app.", retry: false);
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    /**
     * The caller gets the same 401 whichever check failed, so it learns
     * nothing about which one to work around. The real reason goes to the
     * log.
     */
    private function reject(string $reason, bool $retry): never
    {
        Log::info("API request rejected: {$reason}");

        abort(401, 'Unauthorized.', $retry ? self::RETRY_HEADER : []);
    }
}
