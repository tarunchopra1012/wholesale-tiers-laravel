<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shopify;

use App\Services\Shopify\OAuthHmacVerifier;
use PHPUnit\Framework\TestCase;

final class OAuthHmacVerifierTest extends TestCase
{
    private const SECRET = 'test-secret-not-a-real-one';

    /**
     * Shaped like a real callback, keys deliberately out of order so the
     * sort is exercised. The hmac was computed outside PHP, so the test
     * doesn't just re-run the code under test:
     *
     *   printf '%s' 'code=0907a61c0c8d55e99db179b68161bc00&host=YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvZXhhbXBsZS1zdG9yZQ&shop=example-store.myshopify.com&state=8f14e45fceea167a5a36dedd4bea2543&timestamp=1758441600' \
     *     | openssl dgst -sha256 -hmac 'test-secret-not-a-real-one'
     */
    private const CALLBACK = [
        'timestamp' => '1758441600',
        'shop' => 'example-store.myshopify.com',
        'hmac' => 'd012df8cdaaa09525d80425d783996278d5db46aa13e25a07c96154a93e4b85c',
        'code' => '0907a61c0c8d55e99db179b68161bc00',
        'state' => '8f14e45fceea167a5a36dedd4bea2543',
        'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvZXhhbXBsZS1zdG9yZQ',
    ];

    public function test_accepts_a_known_good_signature(): void
    {
        $verifier = new OAuthHmacVerifier(self::SECRET);

        $this->assertTrue($verifier->verify(self::CALLBACK));
    }

    public function test_rejects_a_known_bad_signature(): void
    {
        $verifier = new OAuthHmacVerifier(self::SECRET);

        // Same parameters, last character of the signature changed.
        $tampered = [
            ...self::CALLBACK,
            'hmac' => 'd012df8cdaaa09525d80425d783996278d5db46aa13e25a07c96154a93e4b85d',
        ];

        $this->assertFalse($verifier->verify($tampered));
    }
}
