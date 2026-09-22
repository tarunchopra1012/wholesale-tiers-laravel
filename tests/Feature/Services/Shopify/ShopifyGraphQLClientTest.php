<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Shopify;

use App\Models\Shop;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ShopifyApiException;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A development store almost never throttles, so these fakes are the only
 * way to prove the retry loop works.
 */
final class ShopifyGraphQLClientTest extends TestCase
{
    private const THROTTLED = ['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]];

    protected function setUp(): void
    {
        parent::setUp();

        // Records each wait instead of actually sleeping.
        Sleep::fake();
    }

    public function test_retries_both_kinds_of_throttling_then_returns_the_data(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(self::THROTTLED, 200)
            ->push('', 429)
            ->push(['data' => ['shop' => ['name' => 'Example']]], 200)]);

        $data = $this->client()->query('{ shop { name } }');

        $this->assertSame(['shop' => ['name' => 'Example']], $data);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example-store.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->header('X-Shopify-Access-Token') === ['shpat_test']);
        Sleep::assertSequence([
            Sleep::for(1)->second(),
            Sleep::for(2)->seconds(),
        ]);
    }

    public function test_gives_up_after_two_retries(): void
    {
        Http::fake(['*' => Http::response(self::THROTTLED)]);

        try {
            $this->client()->query('{ shop { name } }');
            $this->fail('Expected ShopifyApiException.');
        } catch (ShopifyApiException $e) {
            $this->assertStringContainsString('throttling', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    private function client(): ShopifyGraphQLClient
    {
        // make(), not create(): the token has an hour left, so nothing here
        // touches the database.
        $shop = Shop::factory()->make([
            'shop_domain' => 'example-store.myshopify.com',
            'access_token' => 'shpat_test',
            'access_token_expires_at' => now()->addHour(),
        ]);

        return new ShopifyGraphQLClient($shop, new OAuthService(
            apiKey: 'test-client-id',
            apiSecret: 'test-secret-not-a-real-one-32-bytes-long',
            scopes: 'read_customers,read_products',
            appUrl: 'https://app.example.test',
        ));
    }
}
