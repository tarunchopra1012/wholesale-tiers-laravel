<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Shopify;

use App\Models\Shop;
use App\Services\Shopify\OAuthException;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ReauthorizationRequiredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AccessTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_with_time_left_is_used_as_is(): void
    {
        Http::fake();
        $shop = Shop::factory()->create([
            'access_token' => 'shpat_current',
            'access_token_expires_at' => now()->addHour(),
        ]);

        $this->assertSame('shpat_current', $this->oauth()->freshAccessToken($shop));
        Http::assertNothingSent();
    }

    public function test_an_expiring_token_is_refreshed_and_both_new_tokens_are_saved(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => 'shpat_new',
            'expires_in' => 3600,
            'refresh_token' => 'shprt_new',
            'refresh_token_expires_in' => 7776000,
            'scope' => 'read_customers,read_products',
        ])]);

        $shop = Shop::factory()->create([
            'shop_domain' => 'example-store.myshopify.com',
            'access_token_expires_at' => now()->addSeconds(30),
            'refresh_token' => 'shprt_old',
        ]);

        $this->assertSame('shpat_new', $this->oauth()->freshAccessToken($shop));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example-store.myshopify.com/admin/oauth/access_token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'shprt_old');

        // Read back from the database, not the in-memory model.
        $saved = Shop::findOrFail($shop->id);
        $this->assertSame('shpat_new', $saved->access_token);
        $this->assertSame('shprt_new', $saved->refresh_token);
        $this->assertTrue($saved->access_token_expires_at->isAfter(now()->addMinutes(59)));
    }

    public function test_no_second_refresh_when_another_request_already_refreshed(): void
    {
        Http::fake();

        $row = Shop::factory()->create(['access_token_expires_at' => now()->addSeconds(30)]);

        // This request loaded the shop while its token was about to expire...
        $stale = Shop::findOrFail($row->id);

        // ...and meanwhile another request refreshed it.
        $row->update([
            'access_token' => 'shpat_refreshed_elsewhere',
            'access_token_expires_at' => now()->addHour(),
        ]);

        $this->assertSame('shpat_refreshed_elsewhere', $this->oauth()->freshAccessToken($stale));
        Http::assertNothingSent();
    }

    public function test_a_refused_refresh_token_sends_the_merchant_back_through_oauth(): void
    {
        // Shopify's documented answer for a refresh token that has expired
        // or been replaced.
        Http::fake(['*' => Http::response(['error' => 'invalid_request'], 401)]);

        $shop = Shop::factory()->create([
            'shop_domain' => 'example-store.myshopify.com',
            'access_token_expires_at' => now()->addSeconds(30),
        ]);

        try {
            $this->oauth()->freshAccessToken($shop);
            $this->fail('Expected ReauthorizationRequiredException.');
        } catch (ReauthorizationRequiredException $e) {
            $this->assertSame('https://app.example.test/auth?shop=example-store.myshopify.com', $e->reauthorizeUrl);
        }
    }

    public function test_a_passing_refresh_failure_is_not_mistaken_for_a_dead_token(): void
    {
        // Shopify down for a moment. The refresh token may be fine, so this
        // must stay an ordinary error the next request retries, not send the
        // merchant through OAuth.
        Http::fake(['*' => Http::response('', 503)]);

        $shop = Shop::factory()->create(['access_token_expires_at' => now()->addSeconds(30)]);

        $this->expectException(OAuthException::class);

        $this->oauth()->freshAccessToken($shop);
    }

    private function oauth(): OAuthService
    {
        return new OAuthService(
            apiKey: 'test-client-id',
            apiSecret: 'test-secret-not-a-real-one-32-bytes-long',
            scopes: 'read_customers,read_products',
            appUrl: 'https://app.example.test',
        );
    }
}
