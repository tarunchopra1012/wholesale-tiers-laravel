<?php

namespace App\Providers;

use App\Services\Shopify\OAuthHmacVerifier;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\SessionTokenVerifier;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Closures, so config is read only when these are first needed: a
        // missing credential breaks /auth, not every page.
        $this->app->bind(OAuthHmacVerifier::class, fn (): OAuthHmacVerifier => new OAuthHmacVerifier(
            secret: $this->requiredShopifyConfig('api_secret'),
        ));

        $this->app->bind(OAuthService::class, fn (): OAuthService => new OAuthService(
            apiKey: $this->requiredShopifyConfig('api_key'),
            apiSecret: $this->requiredShopifyConfig('api_secret'),
            scopes: $this->requiredShopifyConfig('scopes'),
            appUrl: $this->requiredShopifyConfig('app_url'),
        ));

        $this->app->bind(SessionTokenVerifier::class, fn (): SessionTokenVerifier => new SessionTokenVerifier(
            secret: $this->requiredShopifyConfig('api_secret'),
            clientId: $this->requiredShopifyConfig('api_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Scramble reads routes and Form Requests, but not middleware, so it
        // can't see that VerifyShopifySessionToken guards the whole api
        // group. This marks every documented endpoint as needing the ID
        // token as a bearer token.
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
            });
    }

    /**
     * Fail loudly on a missing Shopify setting. Otherwise an empty client ID
     * shows up as a confusing error page on Shopify's side, and an empty
     * secret as a "bad HMAC" 403 on ours.
     */
    private function requiredShopifyConfig(string $key): string
    {
        $value = config("shopify.{$key}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("shopify.{$key} is not set. Add it to .env — see .env.example.");
        }

        return $value;
    }
}
