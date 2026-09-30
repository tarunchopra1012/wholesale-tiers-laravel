<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use RuntimeException;

/**
 * Shopify refuses this shop's tokens, and nothing the server can do will
 * change that: the refresh token has expired or been replaced, or the access
 * token was revoked. Only a new run through OAuth issues fresh ones, and only
 * the merchant can start it.
 *
 * Not an OAuthException, so the API can answer this one with the way out
 * (see bootstrap/app.php), while a passing failure — Shopify unreachable for
 * a moment — stays an ordinary server error that the next request retries.
 *
 * The message is the technical reason, for the log. The merchant sees a
 * plain sentence instead.
 */
final class ReauthorizationRequiredException extends RuntimeException
{
    /**
     * @param  string  $reauthorizeUrl  this app's /auth address for the shop, which starts OAuth again
     */
    public function __construct(
        public readonly string $reauthorizeUrl,
        string $message,
    ) {
        parent::__construct($message);
    }
}
