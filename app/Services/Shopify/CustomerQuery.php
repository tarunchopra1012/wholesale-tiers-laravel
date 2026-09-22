<?php

declare(strict_types=1);

namespace App\Services\Shopify;

/**
 * Pages through a shop's customers, optionally only those with one tag, and
 * flattens Shopify's edges/node envelope into plain arrays so nothing
 * outside this class ever sees it.
 */
final readonly class CustomerQuery
{
    /**
     * Verified against the 2026-07 Admin API (see CLAUDE.md). A customer's
     * email is under defaultEmailAddress — there is no flat `email` field.
     * formattedArea is Shopify's own "city province, country" string, e.g.
     * "Mumbai MH, India"; checked against the dev store on 22 Sep 2026.
     */
    private const QUERY = <<<'GRAPHQL'
        query TieredCustomers($query: String!, $first: Int!, $after: String) {
          customers(first: $first, query: $query, after: $after) {
            edges {
              cursor
              node {
                id
                firstName
                lastName
                tags
                defaultEmailAddress {
                  emailAddress
                }
                defaultAddress {
                  formattedArea
                }
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $client) {}

    /**
     * One page of customers. Pass the previous page's endCursor as $after to
     * get the next one.
     *
     * @return array{
     *     customers: list<array{id: string, firstName: ?string, lastName: ?string, email: ?string, tags: list<string>, location: ?string}>,
     *     pageInfo: array{hasNextPage: bool, endCursor: ?string},
     * }
     *
     * @throws ShopifyApiException
     * @throws OAuthException
     */
    public function page(?string $tag, ?string $after = null, int $first = 25): array
    {
        $data = $this->client->query(self::QUERY, [
            // No tag means an empty search string: the verified query
            // declares $query as required, so it can't just be left out.
            'query' => $tag === null ? '' : "tag:{$tag}",
            'first' => $first,
            'after' => $after,
        ]);

        $connection = $data['customers'] ?? null;

        // Fail loudly on a shape change rather than return an empty list
        // that looks like "no customers".
        if (! is_array($connection) || ! is_array($connection['edges'] ?? null)) {
            throw new ShopifyApiException('Unexpected shape in the customers response.');
        }

        return [
            'customers' => array_map(
                fn (array $edge): array => $this->customer($edge['node']),
                $connection['edges'],
            ),
            'pageInfo' => [
                'hasNextPage' => (bool) ($connection['pageInfo']['hasNextPage'] ?? false),
                'endCursor' => $connection['pageInfo']['endCursor'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, firstName: ?string, lastName: ?string, email: ?string, tags: list<string>, location: ?string}
     */
    private function customer(array $node): array
    {
        return [
            'id' => $node['id'],
            'firstName' => $node['firstName'] ?? null,
            'lastName' => $node['lastName'] ?? null,
            // defaultEmailAddress is null for a customer with no email.
            'email' => $node['defaultEmailAddress']['emailAddress'] ?? null,
            'tags' => $node['tags'] ?? [],
            // defaultAddress is null for a customer with no saved address.
            'location' => $node['defaultAddress']['formattedArea'] ?? null,
        ];
    }
}
