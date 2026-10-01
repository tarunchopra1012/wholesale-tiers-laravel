<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Shopify\OAuthException;
use App\Services\Shopify\OAuthHmacVerifier;
use App\Services\Shopify\OAuthService;
use App\Support\ShopDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shopify's authorization code grant: send the merchant to the consent
 * screen, then verify the callback and store the token.
 *
 * No Form Requests here. These are browser redirects, not JSON API calls,
 * and a failed Form Request redirects "back" — meaningless mid-OAuth.
 */
final class ShopifyOAuthController extends Controller
{
    private const SESSION_KEY = 'shopify_oauth';

    public function __construct(private readonly OAuthService $oauth) {}

    /**
     * GET /auth?shop=… — remember a one-time nonce, then redirect to Shopify.
     */
    public function install(Request $request): RedirectResponse
    {
        $shop = ShopDomain::tryFrom($request->query('shop'))
            ?? abort(400, 'Missing or invalid shop domain.');

        $state = bin2hex(random_bytes(32));

        // The shop is stored with the nonce so a callback for a different
        // shop can't reuse it.
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'shop' => $shop->value,
        ]);

        dump([ // DEBUG(dump)
            'step' => '1. install: nonce saved, redirecting to Shopify consent screen',
            'query' => $request->query(),
            'shop' => $shop->value,
            'state' => $state,
            'session[shopify_oauth]' => $request->session()->get(self::SESSION_KEY),
            'authorize_url' => $this->oauth->authorizeUrl($shop, $state),
        ]);

        return redirect()->away($this->oauth->authorizeUrl($shop, $state));
    }

    /**
     * GET /auth/callback — every check passes before the code is used.
     */
    public function callback(Request $request, OAuthHmacVerifier $hmac): RedirectResponse
    {
        $shop = ShopDomain::tryFrom($request->query('shop'))
            ?? abort(400, 'Missing or invalid shop domain.');

        dump([ // DEBUG(dump)
            'step' => '2. callback: Shopify redirected back',
            'query' => $request->query(),
            'shop' => $shop->value,
        ]);

        if (! $hmac->verify($request->query())) {
            abort(403, 'Invalid HMAC.');
        }

        // pull(), not get(): the nonce is deleted as it is read, so a
        // callback URL works once and a replay fails.
        $expected = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        dump([ // DEBUG(dump)
            'step' => '4. callback: comparing state with the nonce from step 1',
            'session nonce (pulled, now deleted)' => $expected,
            'state from Shopify' => $state,
            'state matches' => is_array($expected) && is_string($state) && hash_equals($expected['state'], $state),
            'shop matches' => is_array($expected) && $expected['shop'] === $shop->value,
        ]);

        if (! is_array($expected)
            || ! is_string($state)
            || ! hash_equals($expected['state'], $state)
            || $expected['shop'] !== $shop->value) {
            abort(403, 'Invalid or expired OAuth state.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            abort(400, 'Missing authorization code.');
        }

        try {
            $this->oauth->install($shop, $code);
        } catch (OAuthException $e) {
            dump([ // DEBUG(dump)
                'step' => 'X. callback: OAuthException, answering 403',
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);
            report($e);
            abort(403, 'Shopify did not grant access.');
        }

        dump([ // DEBUG(dump)
            'step' => '7. callback: installed, redirecting into the admin',
            'admin_app_url' => $this->oauth->adminAppUrl($shop),
        ]);

        return redirect()->away($this->oauth->adminAppUrl($shop));
    }
}
