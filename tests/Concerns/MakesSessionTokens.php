<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Firebase\JWT\JWT;

/**
 * Builds ID tokens shaped like the ones App Bridge sends, signed in the test
 * itself, so no test depends on a hardcoded token string.
 */
trait MakesSessionTokens
{
    /**
     * @param  array<string, mixed>  $overrides  claims to replace or add
     */
    protected function sessionToken(string $shop, string $clientId, string $secret, array $overrides = []): string
    {
        return JWT::encode($this->sessionTokenClaims($shop, $clientId, $overrides), $secret, 'HS256');
    }

    /**
     * The claims Shopify puts in a real ID token, valid for the next minute.
     *
     * @param  array<string, mixed>  $overrides  claims to replace or add
     * @return array<string, mixed>
     */
    protected function sessionTokenClaims(string $shop, string $clientId, array $overrides = []): array
    {
        $now = time();

        return [
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => $clientId,
            'sub' => '42',
            'exp' => $now + 60,
            'nbf' => $now,
            'iat' => $now,
            'jti' => '8f14e45f-ceea-467a-9a36-dedd4bea2543',
            'sid' => 'a1b2c3d4e5f60718',
            ...$overrides,
        ];
    }
}
