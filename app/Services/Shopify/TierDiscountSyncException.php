<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use RuntimeException;

/**
 * The shop's tiers are saved here, but Shopify didn't take them, so
 * checkout still prices with the previous ones.
 */
final class TierDiscountSyncException extends RuntimeException {}
