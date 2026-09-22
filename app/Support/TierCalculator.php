<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DiscountType;
use App\Models\TierSetting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A tier's price for one product. Pure: no database, no HTTP, no facades,
 * so plain PHPUnit can test it without booting the app.
 *
 * Prices are whole cents — hundredths of the shop's currency — and the
 * arithmetic is exact decimal, never float. In a float, 0.1 + 0.2 is not
 * 0.3, and a price is the worst place to find that out.
 */
final readonly class TierCalculator
{
    /**
     * What a customer in $tier pays, in cents. Never below zero.
     *
     * Rounds the final price half-up to the cent — not the discount. The two
     * only differ at an exact half cent: 150¢ at 33% off is 100.5¢, which
     * comes out as 101¢ here. Rounding the discount first would give 100¢.
     */
    public function calculate(int $basePriceCents, TierSetting $tier): int
    {
        $price = BigDecimal::of($basePriceCents);

        // The decimal:2 cast hands this over as a string, e.g. "25.00", so
        // it never passes through a float either.
        $value = BigDecimal::of($tier->discount_value);

        $final = match ($tier->discount_type) {
            DiscountType::Percentage => $price
                ->multipliedBy(BigDecimal::of(100)->minus($value))
                ->dividedByExact(100),
            // A fixed discount is in the shop's currency: 5.00 is 500 cents.
            DiscountType::Fixed => $price->minus($value->multipliedBy(100)),
        };

        // The one place anything is rounded.
        return max(0, $final->toScale(0, RoundingMode::HalfUp)->toInt());
    }
}
