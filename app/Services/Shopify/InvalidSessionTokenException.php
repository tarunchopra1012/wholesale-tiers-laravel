<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use RuntimeException;

/**
 * An ID token from App Bridge failed verification.
 *
 * Its own type so the middleware can catch exactly this and answer 401,
 * without also swallowing unrelated failures such as a database error.
 * The message names the check that failed, for the log. It never contains
 * the token.
 */
final class InvalidSessionTokenException extends RuntimeException {}
