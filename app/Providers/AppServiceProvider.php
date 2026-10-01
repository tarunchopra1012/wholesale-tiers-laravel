<?php

namespace App\Providers;

use App\Services\Shopify\OAuthHmacVerifier;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\SessionTokenVerifier;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Telescope\TelescopeServiceProvider as TelescopePackageServiceProvider;
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
        $this->app->bind(OAuthHmacVerifier::class, fn (): OAuthHmacVerifier => $this->debugBuilt(new OAuthHmacVerifier( // DEBUG(dump): wrapped in debugBuilt()
            secret: $this->requiredShopifyConfig('api_secret'),
        )));

        $this->app->bind(OAuthService::class, fn (): OAuthService => $this->debugBuilt(new OAuthService( // DEBUG(dump): wrapped in debugBuilt()
            apiKey: $this->requiredShopifyConfig('api_key'),
            apiSecret: $this->requiredShopifyConfig('api_secret'),
            scopes: $this->requiredShopifyConfig('scopes'),
            appUrl: $this->requiredShopifyConfig('app_url'),
        )));

        $this->app->bind(SessionTokenVerifier::class, fn (): SessionTokenVerifier => $this->debugBuilt(new SessionTokenVerifier( // DEBUG(dump): wrapped in debugBuilt()
            secret: $this->requiredShopifyConfig('api_secret'),
            clientId: $this->requiredShopifyConfig('api_key'),
        )));

        // Telescope is a dev dependency, so the production image doesn't have
        // it. Registered here, not in bootstrap/providers.php, so it only
        // loads where it's installed and APP_ENV is local.
        if ($this->app->environment('local') && class_exists(TelescopePackageServiceProvider::class)) {
            $this->app->register(TelescopePackageServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
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

        // Laravel's built-in /up route fires this event, and answers 500 if
        // a listener throws. With no listener it answers 200 even when MySQL
        // is down, so a load balancer would keep sending traffic to a
        // container that can't serve a single page.
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
        });

        // On AWS, CloudFront ends HTTPS and reaches the load balancer over
        // plain HTTP, so the load balancer tells Laravel the request was
        // http. Laravel would then write http:// script links into an https
        // page, and the browser blocks them. When the public URL is https,
        // every generated URL is https too. A local run keeps an http://
        // APP_URL and is unaffected.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * DEBUG(dump): shows when the container builds a Shopify service, and
     * the config it was built from. Secret masked.
     *
     * @template T of object
     *
     * @param  T  $service
     * @return T
     */
    private function debugBuilt(object $service): object // DEBUG(dump)
    {
        dump([ // DEBUG(dump)
            'step' => '[container] AppServiceProvider: built '.class_basename($service),
            'shopify config' => [
                'api_key' => config('shopify.api_key'),
                'api_secret (masked)' => \Illuminate\Support\Str::mask((string) config('shopify.api_secret'), '*', 6),
                'scopes' => config('shopify.scopes'),
                'app_url' => config('shopify.app_url'),
                'api_version' => config('shopify.api_version'),
            ],
        ]);

        return $service; // DEBUG(dump)
    } // DEBUG(dump)

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
