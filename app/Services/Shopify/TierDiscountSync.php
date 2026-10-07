<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Enums\CheckoutState;
use App\Models\Shop;
use App\Support\TierDiscountConfig;

/**
 * Copies a shop's tiers to Shopify, where the checkout Function reads them.
 *
 * The shop has one automatic discount that runs the Function. The first sync
 * creates it; every sync writes all of the shop's tiers to its metafield.
 * status() reports whether that discount is still there and switched on.
 */
final readonly class TierDiscountSync
{
    /** What the merchant sees in the admin's Discounts list. */
    public const TITLE = 'Wholesale tiers';

    /** From extensions/wholesale-tier-discount/shopify.extension.toml. */
    private const FUNCTION_HANDLE = 'wholesale-tier-discount';

    private const METAFIELD_NAMESPACE = '$app:wholesale-tiers';

    private const METAFIELD_KEY = 'function-configuration';

    /** Input and payload fields checked against the 2026-07 schema on 5 Oct 2026. */
    private const CREATE = <<<'GRAPHQL'
        mutation CreateTierDiscount($discount: DiscountAutomaticAppInput!) {
          discountAutomaticAppCreate(automaticAppDiscount: $discount) {
            automaticAppDiscount {
              discountId
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

    private const EXISTS = <<<'GRAPHQL'
        query TierDiscount($id: ID!) {
          automaticDiscountNode(id: $id) {
            id
          }
        }
        GRAPHQL;

    /** Checked against the 2026-07 schema on 7 Oct 2026. */
    private const STATUS = <<<'GRAPHQL'
        query TierDiscountStatus($id: ID!) {
          automaticDiscountNode(id: $id) {
            automaticDiscount {
              ... on DiscountAutomaticApp {
                status
              }
            }
          }
        }
        GRAPHQL;

    private const SET_METAFIELD = <<<'GRAPHQL'
        mutation SetTierDiscountConfig($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private TierDiscountConfig $config,
    ) {}

    /**
     * @throws TierDiscountSyncException
     * @throws ReauthorizationRequiredException
     * @throws OAuthException
     */
    public function sync(Shop $shop): void
    {
        $json = json_encode(
            $this->config->build($shop->tierSettings()->orderBy('tag')->get()),
            JSON_THROW_ON_ERROR,
        );

        try {
            // A merchant can delete the discount in the admin. Then the
            // saved ID points at nothing, and a new discount is needed.
            if ($shop->tier_discount_id !== null && $this->exists($shop->tier_discount_id)) {
                $this->setMetafield($shop->tier_discount_id, $json);
            } else {
                $shop->tier_discount_id = $this->create($json);
            }
        } catch (ShopifyApiException $e) {
            throw new TierDiscountSyncException($e->getMessage(), previous: $e);
        }

        $shop->tier_discount_synced_at = now();
        $shop->save();
    }

    /**
     * What has become of the shop's discount in Shopify. Only reads: a
     * deleted discount is reported here and created again by the next sync.
     *
     * @throws ShopifyApiException
     * @throws ReauthorizationRequiredException
     * @throws OAuthException
     */
    public function status(Shop $shop): CheckoutState
    {
        if ($shop->tier_discount_id === null) {
            return CheckoutState::NeverSynced;
        }

        $data = $this->client->query(self::STATUS, ['id' => $shop->tier_discount_id]);

        if ($data['automaticDiscountNode'] === null) {
            return CheckoutState::Missing;
        }

        // Shopify's DiscountStatus is ACTIVE, EXPIRED or SCHEDULED. Switching
        // a discount off in the admin ends it, which shows here as EXPIRED.
        return ($data['automaticDiscountNode']['automaticDiscount']['status'] ?? null) === 'ACTIVE'
            ? CheckoutState::Active
            : CheckoutState::Inactive;
    }

    private function exists(string $discountId): bool
    {
        $data = $this->client->query(self::EXISTS, ['id' => $discountId]);

        return $data['automaticDiscountNode'] !== null;
    }

    /**
     * Creates the discount with its metafield already on it, so the
     * Function never runs without a configuration.
     *
     * @return string the new discount's ID
     */
    private function create(string $json): string
    {
        $data = $this->client->query(self::CREATE, ['discount' => [
            'title' => self::TITLE,
            'functionHandle' => self::FUNCTION_HANDLE,
            'discountClasses' => ['PRODUCT'],
            'startsAt' => now()->toIso8601String(),
            'metafields' => [[
                'namespace' => self::METAFIELD_NAMESPACE,
                'key' => self::METAFIELD_KEY,
                'type' => 'json',
                'value' => $json,
            ]],
        ]]);

        $this->failOnUserErrors($data['discountAutomaticAppCreate']['userErrors']);

        return $data['discountAutomaticAppCreate']['automaticAppDiscount']['discountId'];
    }

    private function setMetafield(string $discountId, string $json): void
    {
        $data = $this->client->query(self::SET_METAFIELD, ['metafields' => [[
            'ownerId' => $discountId,
            'namespace' => self::METAFIELD_NAMESPACE,
            'key' => self::METAFIELD_KEY,
            'type' => 'json',
            'value' => $json,
        ]]]);

        $this->failOnUserErrors($data['metafieldsSet']['userErrors']);
    }

    /**
     * Shopify reports a refused mutation in the payload's userErrors, with
     * HTTP 200 and no top-level errors, so the client can't see it.
     *
     * @param  list<array{field: ?list<string>, message: string}>  $userErrors
     */
    private function failOnUserErrors(array $userErrors): void
    {
        if ($userErrors !== []) {
            throw new ShopifyApiException(
                'Shopify refused the tier discount: '.implode(' ', array_column($userErrors, 'message')),
                $userErrors,
            );
        }
    }
}
