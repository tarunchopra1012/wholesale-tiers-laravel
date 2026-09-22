<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Support\ShopDomain;
use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Checks the ID token App Bridge attaches to every request from the embedded
 * app, and returns the shop it was issued for.
 *
 * No database and no framework calls, so it unit-tests directly. Whether
 * that shop has actually installed the app is the middleware's question,
 * not this class's.
 */
final readonly class SessionTokenVerifier
{
    /**
     * Seconds of clock difference to tolerate between this server and
     * Shopify. Kept small because the token itself only lives 60 seconds.
     */
    private const LEEWAY_SECONDS = 5;

    public function __construct(
        private string $secret,
        private string $clientId,
    ) {}

    /**
     * @throws InvalidSessionTokenException
     */
    public function verify(#[\SensitiveParameter] string $jwt): ShopDomain
    {
        // Fail closed: an empty secret would make every signature forgeable.
        if ($this->secret === '' || $this->clientId === '') {
            throw new InvalidSessionTokenException('Session token verification is not configured.');
        }

        // A static, so it applies to every decode in the process. This is
        // the only class that decodes JWTs, which keeps that harmless.
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            // Proves Shopify signed the token with our secret, and that it is
            // inside its exp/nbf window. Naming HS256 here, instead of
            // trusting the algorithm the token's own header claims, refuses
            // a forged token that says "alg: none".
            $claims = JWT::decode($jwt, new Key($this->secret, 'HS256'));
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $e) {
            throw new InvalidSessionTokenException('Session token rejected: '.$e->getMessage(), previous: $e);
        }

        // php-jwt only checks exp and nbf when they are present. Shopify
        // always sends both, so a token without them is not one of theirs.
        if (! isset($claims->exp, $claims->nbf)) {
            throw new InvalidSessionTokenException('Session token has no exp or nbf claim.');
        }

        // The signature proves who signed the token; aud says who it was
        // for. Shopify requires this check, and it means we don't depend on
        // our secret never being used for anything else.
        if (($claims->aud ?? null) !== $this->clientId) {
            throw new InvalidSessionTokenException('Session token was issued for a different app.');
        }

        // The shop comes from inside the signed token, never from the
        // request. Anything else the browser sends can be edited; this can't
        // be without breaking the signature.
        $dest = $claims->dest ?? null;
        $shop = is_string($dest) ? ShopDomain::tryFrom(parse_url($dest, PHP_URL_HOST)) : null;

        if ($shop === null) {
            throw new InvalidSessionTokenException('Session token has no valid shop in dest.');
        }

        // Shopify requires the issuer and the destination to name the same
        // shop: a cheap check that the token's parts agree with each other.
        $iss = $claims->iss ?? null;
        $issuerHost = is_string($iss) ? parse_url($iss, PHP_URL_HOST) : null;

        if (! is_string($issuerHost) || strtolower($issuerHost) !== $shop->value) {
            throw new InvalidSessionTokenException('Session token iss and dest name different shops.');
        }

        return $shop;
    }
}
