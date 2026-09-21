<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
