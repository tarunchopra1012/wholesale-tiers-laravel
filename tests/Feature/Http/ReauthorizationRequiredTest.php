<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesSessionTokens;
use Tests\TestCase;

/**
 * The whole path, from a real /api request to the JSON React receives, for
 * the one failure only the merchant can fix.
 */
final class ReauthorizationRequiredTest extends TestCase
{
    use MakesSessionTokens;
    use RefreshDatabase;

    private const SECRET = 'test-secret-not-a-real-one-32-bytes-long';

    private const CLIENT_ID = 'test-client-id';

    protected function setUp(): void
    {
        parent::setUp();

        // Test credentials, so nothing here depends on the real .env.
        config()->set('shopify.api_secret', self::SECRET);
        config()->set('shopify.api_key', self::CLIENT_ID);
        config()->set('shopify.scopes', 'read_customers,read_products');
        config()->set('shopify.app_url', 'https://app.example.test');
    }

    public function test_a_dead_refresh_token_answers_403_with_the_way_to_reconnect(): void
    {
        // Shopify's documented answer for a refresh token that has expired
        // or been replaced.
        Http::fake(['*' => Http::response(['error' => 'invalid_request'], 401)]);

        Shop::factory()->create([
            'shop_domain' => 'example-store.myshopify.com',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        // Exact JSON, so this also proves no token rides along in the body.
        $this->withToken($this->sessionToken('example-store.myshopify.com', self::CLIENT_ID, self::SECRET))
            ->getJson('/api/customers')
            ->assertForbidden()
            ->assertExactJson([
                'message' => "Shopify no longer accepts this app's connection to your store. Reconnect to continue.",
                'reauthorize_url' => 'https://app.example.test/auth?shop=example-store.myshopify.com',
            ]);

        // Only the refused refresh went out. The dead token was never sent
        // to the Admin API.
        Http::assertSentCount(1);
    }
}
