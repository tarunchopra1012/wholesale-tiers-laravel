<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\DiscountType;
use App\Models\TierSetting;
use App\Support\TierCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit, not Laravel's TestCase: the calculator needs no app, and
 * extending the plain one proves it. Each tier is built with `new`, which
 * never touches the database.
 */
final class TierCalculatorTest extends TestCase
{
    public function test_zero_percent_leaves_the_price_alone(): void
    {
        $this->assertSame(1299, $this->price(1299, DiscountType::Percentage, '0'));
    }

    public function test_one_hundred_percent_makes_it_free(): void
    {
        $this->assertSame(0, $this->price(1299, DiscountType::Percentage, '100'));
    }

    public function test_takes_a_percentage_off(): void
    {
        // The checkpoint: gold moving from 20% to 25% on a 1299.00 product.
        $this->assertSame(103920, $this->price(129900, DiscountType::Percentage, '20'));
        $this->assertSame(97425, $this->price(129900, DiscountType::Percentage, '25'));
    }

    public function test_takes_a_fixed_amount_off(): void
    {
        // 5.00 in the shop's currency is 500 cents.
        $this->assertSame(1499, $this->price(1999, DiscountType::Fixed, '5.00'));
    }

    public function test_a_fixed_discount_equal_to_the_price_makes_it_free(): void
    {
        $this->assertSame(0, $this->price(1000, DiscountType::Fixed, '10.00'));
    }

    public function test_a_fixed_discount_larger_than_the_price_stops_at_zero(): void
    {
        $this->assertSame(0, $this->price(500, DiscountType::Fixed, '10.00'));
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function roundingCases(): array
    {
        return [
            // 150 × 0.67 = 100.5 exactly. Rounding the discount instead
            // (49.5 → 50) would give 100.
            'exactly half a cent rounds up' => [150, '33', 101],
            // 1 × 0.5 = 0.5: even the smallest price rounds up at the half.
            'half of a single cent rounds up' => [1, '50', 1],
            // 999 × 0.875 = 874.125
            'under half a cent rounds down' => [999, '12.5', 874],
            // 1001 × 0.75 = 750.75
            'over half a cent rounds up' => [1001, '25', 751],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_rounds_half_up_to_the_cent(int $priceCents, string $percentage, int $expected): void
    {
        $this->assertSame($expected, $this->price($priceCents, DiscountType::Percentage, $percentage));
    }

    private function price(int $priceCents, DiscountType $type, string $value): int
    {
        return (new TierCalculator)->calculate($priceCents, new TierSetting([
            'discount_type' => $type,
            'discount_value' => $value,
        ]));
    }
}
