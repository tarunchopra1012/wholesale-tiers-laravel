<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use RuntimeException;

/**
 * Shopify refused to grant access during install, or to renew it later.
 *
 * Its own type so the callback can catch exactly this and answer 403,
 * without also swallowing unrelated failures such as a database error.
 * Messages never contain the secret or a token.
 */
final class OAuthException extends RuntimeException {}
