<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Reads products with their first variant's price, and turns Shopify's
 * decimal price strings into whole cents, so nothing outside this class
 * sees the edges/node envelope or does arithmetic on a price as text.
 */
final readonly class ProductQuery
{
    /**
     * Run against the dev store on 22 Sep 2026 before this class was
     * written. A variant's price is a decimal string such as "1299.00" and
     * carries no currency — that comes from shop.currencyCode.
     */
    private const LIST_QUERY = <<<'GRAPHQL'
        query ProductsWithPrice($first: Int!) {
          shop {
            currencyCode
          }
          products(first: $first, sortKey: TITLE) {
            edges {
              node {
                id
                title
                variants(first: 1) {
                  edges {
                    node {
                      id
                      price
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * Also run against the dev store: an ID the shop doesn't have comes
     * back as product: null, not as an error.
     */
    private const FIND_QUERY = <<<'GRAPHQL'
        query ProductPrice($id: ID!) {
          shop {
            currencyCode
          }
          product(id: $id) {
            id
            title
            variants(first: 1) {
              edges {
                node {
                  id
                  price
                }
              }
            }
          }
        }
        GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $client) {}

    /**
     * The shop's first $count products, by title.
     *
     * @return list<array{id: string, title: string, priceCents: int, currency: string}>
     *
     * @throws ShopifyApiException
     * @throws OAuthException
     */
    public function first(int $count): array
    {
        $data = $this->client->query(self::LIST_QUERY, ['first' => $count]);

        $edges = $data['products']['edges'] ?? null;

        // Fail loudly on a shape change rather than return an empty list
        // that looks like "no products".
        if (! is_array($edges)) {
            throw new ShopifyApiException('Unexpected shape in the products response.');
        }

        $currency = $this->currency($data);

        return array_map(
            fn (array $edge): array => $this->product($edge['node'], $currency),
            $edges,
        );
    }

    /**
     * One product, or null when this shop has no product with that ID.
     *
     * @return array{id: string, title: string, priceCents: int, currency: string}|null
     *
     * @throws ShopifyApiException
     * @throws OAuthException
     */
    public function find(string $id): ?array
    {
        $data = $this->client->query(self::FIND_QUERY, ['id' => $id]);

        // A missing key means the shape changed; null means no such product.
        if (! array_key_exists('product', $data)) {
            throw new ShopifyApiException('Unexpected shape in the product response.');
        }

        if ($data['product'] === null) {
            return null;
        }

        return $this->product($data['product'], $this->currency($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function currency(array $data): string
    {
        $currency = $data['shop']['currencyCode'] ?? null;

        if (! is_string($currency)) {
            throw new ShopifyApiException('Unexpected shape: no shop currency in the response.');
        }

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, title: string, priceCents: int, currency: string}
     */
    private function product(array $node, string $currency): array
    {
        // Every Shopify product has at least one variant, so a missing price
        // is a shape change, not a product without one.
        $price = $node['variants']['edges'][0]['node']['price'] ?? null;

        if (! is_string($price)) {
            throw new ShopifyApiException("Product {$node['id']} came back without a price.");
        }

        return [
            'id' => $node['id'],
            'title' => $node['title'],
            'priceCents' => $this->cents($price),
            'currency' => $currency,
        ];
    }

    /**
     * "1299.00" → 129900. Throws rather than rounds when the price has a
     * fraction of a cent: that only happens in a currency with three
     * decimals, where cents are the wrong unit and rounding would misprice.
     */
    private function cents(string $price): int
    {
        try {
            return BigDecimal::of($price)->multipliedBy(100)->toScale(0)->toInt();
        } catch (MathException $e) {
            throw new ShopifyApiException("Unexpected price format: {$price}.", previous: $e);
        }
    }
}
