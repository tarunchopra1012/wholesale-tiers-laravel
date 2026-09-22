<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use RuntimeException;
use Throwable;

/**
 * A GraphQL request to Shopify's Admin API failed: couldn't connect, a
 * non-2xx answer, throttled past the retries, or GraphQL errors in the body.
 *
 * Carries Shopify's `errors` array, when there is one, so a caller can look
 * at it. The message never contains the access token.
 */
final class ShopifyApiException extends RuntimeException
{
    /**
     * @param  array<mixed>  $errors  the response's `errors` value, if any
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
