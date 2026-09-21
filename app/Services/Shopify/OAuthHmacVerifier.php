<?php

declare(strict_types=1);

namespace App\Services\Shopify;

/**
 * Checks the hmac Shopify adds to the OAuth callback URL.
 *
 * Shopify signs every other query parameter with the app's secret. If our
 * signature matches theirs, the request came from Shopify and no parameter
 * was changed on the way. No framework calls, so it unit-tests directly.
 */
final readonly class OAuthHmacVerifier
{
    public function __construct(private string $secret) {}

    /**
     * @param  array<string, mixed>  $query  the callback's query parameters, as received
     */
    public function verify(array $query): bool
    {
        $given = $query['hmac'] ?? null;

        // Fail closed: an empty secret would make every signature forgeable.
        if ($this->secret === '' || ! is_string($given) || $given === '') {
            return false;
        }

        // The signed message is every other parameter, sorted by key and
        // joined as a query string.
        unset($query['hmac']);
        ksort($query, SORT_STRING);

        $expected = hash_hmac('sha256', http_build_query($query), $this->secret);

        // Constant-time: a plain === returns sooner the earlier the first
        // wrong character is, which leaks the signature byte by byte.
        return hash_equals($expected, $given);
    }
}
