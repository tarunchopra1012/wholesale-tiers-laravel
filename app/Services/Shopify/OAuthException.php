<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use RuntimeException;

/**
 * Shopify refused to grant access during install, or a token refresh failed
 * for a reason a later request may get past. When Shopify refuses the
 * refresh token itself, that is a ReauthorizationRequiredException instead.
 *
 * Its own type so the callback can catch exactly this and answer 403,
 * without also swallowing unrelated failures such as a database error.
 * Messages never contain the secret or a token.
 */
final class OAuthException extends RuntimeException {}
