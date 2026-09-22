<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\MakesSessionTokens;
use Tests\TestCase;

final class VerifyShopifySessionTokenTest extends TestCase
{
    use MakesSessionTokens;
    use RefreshDatabase;

    private const SECRET = 'test-secret-not-a-real-one-32-bytes-long';

    private const CLIENT_ID = 'test-client-id';

    private const RETRY_HEADER = 'X-Shopify-Retry-Invalid-Session-Request';

    protected function setUp(): void
    {
        parent::setUp();

        // Test credentials, so nothing here depends on the real .env.
        config()->set('shopify.api_secret', self::SECRET);
        config()->set('shopify.api_key', self::CLIENT_ID);

        // Reports which shop the middleware resolved and nothing more, so
        // these tests cover the middleware alone.
        Route::middleware('api')->prefix('api')->get('/_probe', fn (Request $request): array => [
            'shop' => $request->attributes->get('shop')->shop_domain,
        ]);
    }

    public function test_rejects_a_request_without_a_token_and_asks_app_bridge_to_retry(): void
    {
        $this->getJson('/api/_probe')
            ->assertUnauthorized()
            ->assertHeader(self::RETRY_HEADER, '1');
    }

    public function test_rejects_a_valid_token_for_an_uninstalled_shop_without_asking_to_retry(): void
    {
        Shop::factory()->uninstalled()->create(['shop_domain' => 'gone-store.myshopify.com']);

        $this->withToken($this->tokenFor('gone-store.myshopify.com'))
            ->getJson('/api/_probe')
            ->assertUnauthorized()
            ->assertHeaderMissing(self::RETRY_HEADER);
    }

    public function test_the_shop_comes_from_the_token_not_from_the_query_string(): void
    {
        Shop::factory()->create(['shop_domain' => 'caller-store.myshopify.com']);
        Shop::factory()->create(['shop_domain' => 'victim-store.myshopify.com']);

        // Signed in as caller-store, asking for victim-store in the URL.
        $this->withToken($this->tokenFor('caller-store.myshopify.com'))
            ->getJson('/api/_probe?shop=victim-store.myshopify.com')
            ->assertOk()
            ->assertExactJson(['shop' => 'caller-store.myshopify.com']);
    }

    private function tokenFor(string $shop): string
    {
        return $this->sessionToken($shop, self::CLIENT_ID, self::SECRET);
    }
}
