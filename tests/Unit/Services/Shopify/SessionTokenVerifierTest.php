<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shopify;

use App\Services\Shopify\InvalidSessionTokenException;
use App\Services\Shopify\SessionTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MakesSessionTokens;

final class SessionTokenVerifierTest extends TestCase
{
    use MakesSessionTokens;

    /**
     * At least 32 bytes: php-jwt v7 refuses shorter HS256 keys. A too-short
     * key would make every rejection test below pass for the wrong reason,
     * which is why each one also checks the message.
     */
    private const SECRET = 'test-secret-not-a-real-one-32-bytes-long';

    private const CLIENT_ID = 'test-client-id';

    private const SHOP = 'example-store.myshopify.com';

    public function test_accepts_a_valid_token_and_returns_its_shop(): void
    {
        $shop = $this->verifier()->verify($this->token());

        $this->assertSame(self::SHOP, $shop->value);
    }

    public function test_rejects_an_expired_token(): void
    {
        // Issued two minutes ago with the usual 60-second life, so it
        // expired well outside the 5-second leeway.
        $token = $this->token([
            'iat' => time() - 120,
            'nbf' => time() - 120,
            'exp' => time() - 60,
        ]);

        $this->expectException(InvalidSessionTokenException::class);
        $this->expectExceptionMessage('Expired token');

        $this->verifier()->verify($token);
    }

    public function test_rejects_a_token_issued_for_a_different_app(): void
    {
        $token = $this->token(['aud' => 'some-other-app']);

        $this->expectException(InvalidSessionTokenException::class);
        $this->expectExceptionMessage('different app');

        $this->verifier()->verify($token);
    }

    public function test_rejects_a_token_whose_payload_was_edited_after_signing(): void
    {
        [$header, , $signature] = explode('.', $this->token());

        // The real attack: keep Shopify's signature, but point the token at
        // a shop the caller doesn't own. iss is changed too, so the only
        // thing wrong with this token is the signature.
        $forgedPayload = JWT::urlsafeB64Encode(json_encode(
            $this->sessionTokenClaims('victim-store.myshopify.com', self::CLIENT_ID),
            JSON_THROW_ON_ERROR,
        ));

        $this->expectException(InvalidSessionTokenException::class);
        $this->expectExceptionMessage('Signature verification failed');

        $this->verifier()->verify("{$header}.{$forgedPayload}.{$signature}");
    }

    private function verifier(): SessionTokenVerifier
    {
        return new SessionTokenVerifier(self::SECRET, self::CLIENT_ID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function token(array $overrides = []): string
    {
        return $this->sessionToken(self::SHOP, self::CLIENT_ID, self::SECRET, $overrides);
    }
}
