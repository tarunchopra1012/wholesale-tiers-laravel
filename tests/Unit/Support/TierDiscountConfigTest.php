<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\DiscountType;
use App\Models\TierSetting;
use App\Support\TierDiscountConfig;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit, like TierCalculatorTest: the config needs no app.
 */
final class TierDiscountConfigTest extends TestCase
{
    public function test_lists_each_tier_and_its_tag(): void
    {
        $config = (new TierDiscountConfig)->build([
            $this->tier('wholesale-gold', DiscountType::Percentage, '20'),
            $this->tier('wholesale-silver', DiscountType::Fixed, '5.5'),
        ]);

        $this->assertSame([
            'tags' => ['wholesale-gold', 'wholesale-silver'],
            'tiers' => [
                ['tag' => 'wholesale-gold', 'type' => 'percentage', 'value' => '20.00'],
                ['tag' => 'wholesale-silver', 'type' => 'fixed', 'value' => '5.50'],
            ],
        ], $config);
    }

    public function test_no_tiers_gives_empty_lists_not_missing_keys(): void
    {
        // The Function reads both keys, so both must be there.
        $this->assertSame(['tags' => [], 'tiers' => []], (new TierDiscountConfig)->build([]));
    }

    private function tier(string $tag, DiscountType $type, string $value): TierSetting
    {
        return new TierSetting(['tag' => $tag, 'discount_type' => $type, 'discount_value' => $value]);
    }
}
