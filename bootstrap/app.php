<?php

use App\Http\Middleware\VerifyShopifySessionToken;
use App\Services\Shopify\ReauthorizationRequiredException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shopify signs the OAuth callback's parameters exactly as sent. The
        // default input clean-up (trim, '' to null) also rewrites query
        // params, which would change what the HMAC is checked against.
        $isOAuthCallback = fn (Request $request): bool => $request->is('auth/callback');

        $middleware->trimStrings(except: [$isOAuthCallback]);
        $middleware->convertEmptyStringsToNull(except: [$isOAuthCallback]);

        // The tunnel ends HTTPS and reaches nginx over plain HTTP, so without
        // this Laravel writes http:// asset links into an https page, and
        // Chrome blocks them as mixed content. Only the scheme header is
        // trusted: the Host header already arrives intact, and a spoofed
        // X-Forwarded-Proto can only change links on the sender's own page.
        // Revisit at deployment, where the proxy's address can be pinned.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);

        // On the whole group, not per route, so every /api route is
        // protected by default. The api group has no session or CSRF
        // middleware unless statefulApi() is called — and it isn't: the ID
        // token is the only credential, with no cookies to forge.
        $middleware->api(append: [VerifyShopifySessionToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The shop's tokens are dead, and the merchant is the only one who
        // can renew them. A 403, not a 500: nothing on the server is broken.
        // Not a 401 either: that means a bad ID token, and this one was fine.
        // The exception is still reported, so its technical reason reaches
        // the log; the merchant gets a plain sentence and the way out.
        $exceptions->render(fn (ReauthorizationRequiredException $e): JsonResponse => response()->json([
            'message' => "Shopify no longer accepts this app's connection to your store. Reconnect to continue.",
            'reauthorize_url' => $e->reauthorizeUrl,
        ], 403));
    })->create();
