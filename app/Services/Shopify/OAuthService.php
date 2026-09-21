<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Support\ShopDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The two server-side halves of Shopify's authorization code grant:
 * building the consent URL, and trading the returned code for a token.
 *
 * The callback's security checks (hmac, state) happen in the controller
 * before install() is called; this class trusts that they passed.
 */
final readonly class OAuthService
{
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

        $accessToken = $response->json('access_token');
        $grantedScopes = $response->json('scope');

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($grantedScopes)) {
            throw new OAuthException("Token exchange for {$shop} returned no access token.");
        }

        $missing = $this->missingScopes($grantedScopes);

        if ($missing !== []) {
            throw new OAuthException("{$shop} did not grant: ".implode(', ', $missing).'.');
        }

        // Only present when Shopify issued an expiring token. Null in the
        // row means "does not expire".
        $expiresIn = $response->json('expires_in');
        $refreshToken = $response->json('refresh_token');
        $refreshExpiresIn = $response->json('refresh_token_expires_in');

        return Shop::updateOrCreate(
            ['shop_domain' => $shop->value],
            [
                'access_token' => $accessToken,
                'access_token_expires_at' => is_int($expiresIn) ? now()->addSeconds($expiresIn) : null,
                'refresh_token' => is_string($refreshToken) ? $refreshToken : null,
                'refresh_token_expires_at' => is_int($refreshExpiresIn) ? now()->addSeconds($refreshExpiresIn) : null,
                'scopes' => $grantedScopes,
                'installed_at' => now(),
                // A reinstall revives the existing row and its tiers.
                'uninstalled_at' => null,
            ],
        );
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
