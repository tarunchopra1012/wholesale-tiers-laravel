<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Shopify;

use App\Enums\CheckoutState;
use App\Models\Shop;
use App\Models\TierSetting;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\TierDiscountSync;
use App\Services\Shopify\TierDiscountSyncException;
use App\Support\TierDiscountConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TierDiscountSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DISCOUNT_ID = 'gid://shopify/DiscountAutomaticNode/1';

    private const CONFIG = '{"tags":["wholesale-gold"],"tiers":[{"tag":"wholesale-gold","name":"wholesale-gold","type":"percentage","value":"20.00"}]}';

    public function test_the_first_sync_creates_the_discount_with_the_tiers_on_it(): void
    {
        Http::fake(['*' => Http::response(['data' => ['discountAutomaticAppCreate' => [
            'automaticAppDiscount' => ['discountId' => self::DISCOUNT_ID],
            'userErrors' => [],
        ]]])]);
        $shop = $this->shopWithGoldTier();

        $this->sync($shop);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $discount = $this->variables($request)['discount'];

            return $discount['functionHandle'] === 'wholesale-tier-discount'
                && $discount['discountClasses'] === ['PRODUCT']
                && $discount['metafields'][0]['namespace'] === '$app:wholesale-tiers'
                && $discount['metafields'][0]['key'] === 'function-configuration'
                && $discount['metafields'][0]['type'] === 'json'
                && $discount['metafields'][0]['value'] === self::CONFIG;
        });

        $saved = Shop::findOrFail($shop->id);
        $this->assertSame(self::DISCOUNT_ID, $saved->tier_discount_id);
        $this->assertNotNull($saved->tier_discount_synced_at);
    }

    public function test_a_later_sync_writes_the_metafield_of_the_same_discount(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['automaticDiscountNode' => ['id' => self::DISCOUNT_ID]]])
            ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]])]);
        $shop = $this->shopWithGoldTier(['tier_discount_id' => self::DISCOUNT_ID]);

        $this->sync($shop);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($this->variables($request)['metafields'][0] ?? null) === [
            'ownerId' => self::DISCOUNT_ID,
            'namespace' => '$app:wholesale-tiers',
            'key' => 'function-configuration',
            'type' => 'json',
            'value' => self::CONFIG,
        ]);
        $this->assertSame(self::DISCOUNT_ID, Shop::findOrFail($shop->id)->tier_discount_id);
    }

    public function test_a_discount_deleted_in_the_admin_is_created_again(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['automaticDiscountNode' => null]])
            ->push(['data' => ['discountAutomaticAppCreate' => [
                'automaticAppDiscount' => ['discountId' => 'gid://shopify/DiscountAutomaticNode/2'],
                'userErrors' => [],
            ]]])]);
        $shop = $this->shopWithGoldTier(['tier_discount_id' => self::DISCOUNT_ID]);

        $this->sync($shop);

        $this->assertSame('gid://shopify/DiscountAutomaticNode/2', Shop::findOrFail($shop->id)->tier_discount_id);
    }

    public function test_user_errors_fail_the_sync_and_save_nothing(): void
    {
        // HTTP 200 and no top-level errors: only userErrors says it failed.
        Http::fake(['*' => Http::response(['data' => ['discountAutomaticAppCreate' => [
            'automaticAppDiscount' => null,
            'userErrors' => [['field' => ['automaticAppDiscount', 'functionHandle'], 'message' => 'Function not found.']],
        ]]])]);
        $shop = $this->shopWithGoldTier();

        try {
            $this->sync($shop);
            $this->fail('Expected TierDiscountSyncException.');
        } catch (TierDiscountSyncException $e) {
            $this->assertStringContainsString('Function not found.', $e->getMessage());
        }

        $saved = Shop::findOrFail($shop->id);
        $this->assertNull($saved->tier_discount_id);
        $this->assertNull($saved->tier_discount_synced_at);
    }

    public function test_status_of_a_shop_that_never_synced_asks_shopify_nothing(): void
    {
        Http::fake();

        $this->assertSame(CheckoutState::NeverSynced, $this->statusOf($this->shopWithGoldTier()));
        Http::assertNothingSent();
    }

    public function test_status_is_active_while_shopify_applies_the_discount(): void
    {
        $this->fakeDiscountNode(['automaticDiscount' => ['status' => 'ACTIVE']]);

        $this->assertSame(CheckoutState::Active, $this->statusOfSyncedShop());
        Http::assertSent(fn (Request $request): bool => $this->variables($request) === ['id' => self::DISCOUNT_ID]);
    }

    public function test_status_is_inactive_when_the_discount_is_switched_off(): void
    {
        // Deactivating a discount in the admin ends it.
        $this->fakeDiscountNode(['automaticDiscount' => ['status' => 'EXPIRED']]);

        $this->assertSame(CheckoutState::Inactive, $this->statusOfSyncedShop());
    }

    public function test_status_is_missing_when_the_discount_was_deleted(): void
    {
        $this->fakeDiscountNode(null);

        $this->assertSame(CheckoutState::Missing, $this->statusOfSyncedShop());
    }

    /**
     * @param  array<string, mixed>|null  $node
     */
    private function fakeDiscountNode(?array $node): void
    {
        Http::fake(['*' => Http::response(['data' => ['automaticDiscountNode' => $node]])]);
    }

    private function statusOfSyncedShop(): CheckoutState
    {
        return $this->statusOf($this->shopWithGoldTier(['tier_discount_id' => self::DISCOUNT_ID]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shopWithGoldTier(array $attributes = []): Shop
    {
        $shop = Shop::factory()->create(['shop_domain' => 'example-store.myshopify.com', ...$attributes]);
        TierSetting::factory()->for($shop)->percentage(20)->create(['tag' => 'wholesale-gold']);

        return $shop;
    }

    /**
     * Decoded from the body: the client sends variables as an object, which
     * $request['variables'] would hand back as a stdClass.
     *
     * @return array<string, mixed>
     */
    private function variables(Request $request): array
    {
        return json_decode($request->body(), true)['variables'];
    }

    private function sync(Shop $shop): void
    {
        $this->tierDiscountSync($shop)->sync($shop);
    }

    private function statusOf(Shop $shop): CheckoutState
    {
        return $this->tierDiscountSync($shop)->status($shop);
    }

    private function tierDiscountSync(Shop $shop): TierDiscountSync
    {
        $client = new ShopifyGraphQLClient($shop, new OAuthService(
            apiKey: 'test-client-id',
            apiSecret: 'test-secret-not-a-real-one-32-bytes-long',
            scopes: 'read_customers,read_products,write_discounts',
            appUrl: 'https://app.example.test',
        ));

        return new TierDiscountSync($client, new TierDiscountConfig);
    }
}
