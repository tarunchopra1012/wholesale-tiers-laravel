<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Sends GraphQL queries to one shop's Admin API, using that shop's own
 * offline token. The only place in the app that talks to the Admin API, so
 * the token never has a reason to leave the server.
 */
final readonly class ShopifyGraphQLClient
{
    /**
     * Seconds to wait before each retry after a throttled answer: doubling,
     * two retries. The query budget refills at a steady rate, so waiting
     * longer each time gives it room to recover.
     */
    private const RETRY_DELAYS_SECONDS = [1, 2];

    public function __construct(
        private Shop $shop,
        private OAuthService $oauth,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>  the response's `data` block
     *
     * @throws ShopifyApiException
     * @throws ReauthorizationRequiredException when Shopify no longer
     *                                          accepts this shop's tokens
     * @throws OAuthException when the access token can't be refreshed
     */
    public function query(string $query, array $variables = []): array
    {
        dump([ // DEBUG(dump)
            'step' => '[shopify] query: sending GraphQL',
            'shop' => $this->shop->shop_domain,
            'operation' => preg_match('/(query|mutation)\s+(\w+)/', $query, $m) ? "{$m[1]} {$m[2]}" : '(anonymous)',
            'variables' => $variables,
            'query' => $query,
        ]);

        $response = $this->send($query, $variables);

        foreach (self::RETRY_DELAYS_SECONDS as $seconds) {
            if (! $this->isThrottled($response)) {
                break;
            }

            dump([ // DEBUG(dump)
                'step' => '[shopify] query: THROTTLED, backing off before retrying',
                'wait seconds' => $seconds,
                'cost' => $response->json('extensions.cost'),
            ]);

            Sleep::for($seconds)->seconds();
            $response = $this->send($query, $variables);
        }

        if ($this->isThrottled($response)) {
            throw new ShopifyApiException("Shopify was still throttling requests for {$this->shop->shop_domain} after retrying.");
        }

        // send() refreshed the token if it was near expiry, so a 401 means
        // Shopify has revoked it. Sending it again can't help.
        if ($response->status() === 401) {
            throw new ReauthorizationRequiredException(
                $this->oauth->reauthorizeUrl($this->shop),
                "Shopify rejected the access token for {$this->shop->shop_domain}.",
            );
        }

        if ($response->failed()) {
            throw new ShopifyApiException(sprintf(
                'Shopify answered HTTP %d for %s.',
                $response->status(),
                $this->shop->shop_domain,
            ));
        }

        $errors = $response->json('errors');

        if ($errors !== null && $errors !== []) {
            throw new ShopifyApiException('Shopify returned GraphQL errors.', is_array($errors) ? $errors : [$errors]);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new ShopifyApiException('Shopify returned no data.');
        }

        dump([ // DEBUG(dump)
            'step' => '[shopify] query: raw `data` block from Shopify',
            'data' => $data,
        ]);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws ShopifyApiException when Shopify can't be reached
     */
    private function send(string $query, array $variables): Response
    {
        // Fetched per attempt, so a retry after a wait never goes out with a
        // token that expired during that wait.
        $token = $this->oauth->freshAccessToken($this->shop);

        $url = sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->shop->shop_domain,
            config('shopify.api_version'),
        );

        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->timeout(10)
                ->post($url, [
                    'query' => $query,
                    // Cast so an empty array is sent as {}, not []: GraphQL
                    // expects variables to be an object.
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException $e) {
            throw new ShopifyApiException("Could not reach {$this->shop->shop_domain}.", previous: $e);
        }

        dump([ // DEBUG(dump)
            'step' => '[shopify] send: HTTP round trip done',
            'POST' => $url,
            'X-Shopify-Access-Token (masked)' => \Illuminate\Support\Str::mask($token, '*', 8),
            'status' => $response->status(),
            'cost (requested / actual / budget left)' => $response->json('extensions.cost'),
            'errors' => $response->json('errors'),
        ]);

        // The cost block and the shop — never the variables or the body.
        // Customer data is protected; it doesn't belong in a log file.
        Log::debug('Shopify GraphQL cost', [
            'shop' => $this->shop->shop_domain,
            'status' => $response->status(),
            'cost' => $response->json('extensions.cost'),
        ]);

        return $response;
    }

    /**
     * Shopify signals throttling two ways: HTTP 429, or — more often for
     * GraphQL — a normal 200 with THROTTLED in the errors. Laravel's
     * Http::retry() only reacts to failed status codes and would miss the
     * second, which is why the retry loop is written out by hand.
     */
    private function isThrottled(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (is_array($error) && ($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }
}
