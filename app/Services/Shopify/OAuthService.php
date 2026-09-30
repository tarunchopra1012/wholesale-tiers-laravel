<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Support\ShopDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The server-side halves of Shopify's authorization code grant — building
 * the consent URL, trading the returned code for a token — plus keeping
 * that expiring token alive afterwards.
 *
 * The callback's security checks (hmac, state) happen in the controller
 * before install() is called; this class trusts that they passed.
 */
final readonly class OAuthService
{
    /**
     * Refresh this long before the access token expires, so a token can't
     * die between being handed out and being used. Shopify's own example
     * uses the same minute.
     */
    private const REFRESH_MARGIN_SECONDS = 60;

    public function __construct(
        private string $apiKey,
        private string $apiSecret,
        private string $scopes,
        private string $appUrl,
    ) {}

    /**
     * Shopify's consent screen for this shop.
     */
    public function authorizeUrl(ShopDomain $shop, string $state): string
    {
        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => $this->apiKey,
            'scope' => $this->scopes,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * Where to send the merchant once installed: the app, open inside
     * their admin.
     */
    public function adminAppUrl(ShopDomain $shop): string
    {
        return "https://{$shop}/admin/apps/{$this->apiKey}";
    }

    /**
     * Where to send a merchant whose tokens have died: the start of OAuth,
     * which issues new ones. Absolute and built from config, like the
     * redirect URI, because the merchant opens it outside the admin's frame.
     *
     * Takes a Shop, not a ShopDomain: the domain is only a query value here,
     * which route() encodes, and /auth checks it before using it.
     */
    public function reauthorizeUrl(Shop $shop): string
    {
        return rtrim($this->appUrl, '/').route('auth.install', ['shop' => $shop->shop_domain], absolute: false);
    }

    /**
     * Trade the one-time code for an expiring offline token and save it.
     *
     * @throws OAuthException when Shopify can't be reached, refuses the code,
     *                        or grants fewer scopes than we asked for
     */
    public function install(ShopDomain $shop, string $code): Shop
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                    'code' => $code,
                    'expiring' => '1',
                ]);
        } catch (ConnectionException $e) {
            throw new OAuthException("Could not reach {$shop} to exchange the code.", previous: $e);
        }

        if ($response->failed()) {
            $reason = $response->json('error_description');

            throw new OAuthException(sprintf(
                'Token exchange for %s failed with HTTP %d%s',
                $shop,
                $response->status(),
                is_string($reason) ? ": {$reason}" : '.',
            ));
        }

        $tokens = $this->tokenColumns($response, $shop);
        $grantedScopes = $response->json('scope');

        if (! is_string($grantedScopes)) {
            throw new OAuthException("Token exchange for {$shop} returned no scopes.");
        }

        $missing = $this->missingScopes($grantedScopes);

        if ($missing !== []) {
            throw new OAuthException("{$shop} did not grant: ".implode(', ', $missing).'.');
        }

        return Shop::updateOrCreate(
            ['shop_domain' => $shop->value],
            [
                ...$tokens,
                'scopes' => $grantedScopes,
                'installed_at' => now(),
                // A reinstall revives the existing row and its tiers.
                'uninstalled_at' => null,
            ],
        );
    }

    /**
     * The shop's access token, refreshed first if it expires within a
     * minute.
     *
     * @throws ReauthorizationRequiredException when Shopify refuses the
     *                                          refresh token. Only the
     *                                          merchant can fix that
     * @throws OAuthException when the refresh fails for any other reason,
     *                        which the next request may get past
     */
    public function freshAccessToken(Shop $shop): string
    {
        if (! $this->expiresSoon($shop)) {
            return $shop->access_token;
        }

        // Shopify's docs: refresh one store at a time. Two requests
        // refreshing together each retire the other's result, so one ends up
        // holding a token that's already dead. The lock makes the second one
        // wait. It outlives the 10-second HTTP timeout below, so it can't
        // expire while a refresh is still in flight.
        return Cache::lock("shopify-token-refresh:{$shop->id}", 30)->block(10, function () use ($shop): string {
            // Reload inside the lock. If another request refreshed while this
            // one waited, the row is fresh now and there's nothing to do.
            // Without this, the lock would only make the two refreshes take
            // turns — both would still happen.
            $shop->refresh();

            if ($this->expiresSoon($shop)) {
                $this->refreshAccessToken($shop);
            }

            return $shop->access_token;
        });
    }

    /**
     * Must match the redirect URL in the Partner Dashboard character for
     * character, so it's built from config, not from the incoming request.
     */
    private function redirectUri(): string
    {
        return rtrim($this->appUrl, '/').route('auth.callback', absolute: false);
    }

    /**
     * Null means Shopify issued a token that doesn't expire.
     */
    private function expiresSoon(Shop $shop): bool
    {
        return $shop->access_token_expires_at !== null
            && $shop->access_token_expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    /**
     * Swap the refresh token for a new access token, and save it.
     *
     * @throws ReauthorizationRequiredException
     * @throws OAuthException
     */
    private function refreshAccessToken(Shop $shop): void
    {
        // Checked again although install() already did: the app-wide client
        // secret is about to be posted to this host.
        $domain = ShopDomain::tryFrom($shop->shop_domain)
            ?? throw new OAuthException("Stored shop domain {$shop->shop_domain} is not valid.");

        if ($shop->refresh_token === null) {
            throw new ReauthorizationRequiredException(
                $this->reauthorizeUrl($shop),
                "{$domain} has an expiring token but no refresh token.",
            );
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post("https://{$domain}/admin/oauth/access_token", [
                    'client_id' => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $shop->refresh_token,
                ]);
        } catch (ConnectionException $e) {
            throw new OAuthException("Could not reach {$domain} to refresh its token.", previous: $e);
        }

        // Shopify answers 401 {"error":"invalid_request"} when the refresh
        // token has expired or been replaced. That is final: retrying won't
        // revive it, and Shopify's advice is to send the merchant through
        // OAuth again.
        if ($response->status() === 401) {
            throw new ReauthorizationRequiredException(
                $this->reauthorizeUrl($shop),
                "Shopify refused the refresh token for {$domain}.",
            );
        }

        // Anything else — a 5xx, say — says nothing about the refresh
        // token, which may still be fine. The next request tries again.
        if ($response->failed()) {
            throw new OAuthException(sprintf(
                'Token refresh for %s failed with HTTP %d.',
                $domain,
                $response->status(),
            ));
        }

        // Every refresh also issues a new refresh token, and the old one stops
        // working once the new one is used. Both have to be saved, or the
        // next refresh fails.
        $shop->update($this->tokenColumns($response, $domain));
    }

    /**
     * The token columns from a code exchange or a refresh — Shopify answers
     * both with the same fields.
     *
     * @return array{access_token: string, access_token_expires_at: ?\Illuminate\Support\Carbon, refresh_token: ?string, refresh_token_expires_at: ?\Illuminate\Support\Carbon}
     *
     * @throws OAuthException when the response carries no access token
     */
    private function tokenColumns(Response $response, ShopDomain $shop): array
    {
        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new OAuthException("Shopify returned no access token for {$shop}.");
        }

        // Only present when Shopify issued an expiring token. Null in the
        // row means "does not expire".
        $expiresIn = $response->json('expires_in');
        $refreshToken = $response->json('refresh_token');
        $refreshExpiresIn = $response->json('refresh_token_expires_in');

        return [
            'access_token' => $accessToken,
            'access_token_expires_at' => is_int($expiresIn) ? now()->addSeconds($expiresIn) : null,
            'refresh_token' => is_string($refreshToken) ? $refreshToken : null,
            'refresh_token_expires_at' => is_int($refreshExpiresIn) ? now()->addSeconds($refreshExpiresIn) : null,
        ];
    }

    /**
     * Requested scopes Shopify did not grant. A write_ scope includes its
     * read_ scope, and Shopify may return only the write_ one.
     *
     * @return list<string>
     */
    private function missingScopes(string $granted): array
    {
        $granted = array_map(trim(...), explode(',', $granted));
        $requested = array_filter(array_map(trim(...), explode(',', $this->scopes)));

        return array_values(array_filter(
            $requested,
            fn (string $scope): bool => ! in_array($scope, $granted, true)
                && ! (str_starts_with($scope, 'read_')
                    && in_array('write_'.substr($scope, 5), $granted, true)),
        ));
    }
}
