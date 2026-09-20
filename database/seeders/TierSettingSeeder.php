<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\TierSetting;
use Illuminate\Database\Seeder;

class TierSettingSeeder extends Seeder
{
    /**
     * The development shop these tiers belong to.
     */
    private const SHOP_DOMAIN = 'wholesale-tiers-dev.myshopify.com';

    /**
     * Percentage discount per customer tag.
     *
     * @var array<string, float>
     */
    private const TIERS = [
        'wholesale-gold' => 20.00,
        'wholesale-silver' => 10.00,
    ];

    /**
     * Seed one shop with two wholesale tiers.
     */
    public function run(): void
    {
        $shop = Shop::query()->firstWhere('shop_domain', self::SHOP_DOMAIN)
            ?? Shop::factory()->create(['shop_domain' => self::SHOP_DOMAIN]);

        foreach (self::TIERS as $tag => $percentage) {
            if ($shop->tierSettings()->where('tag', $tag)->exists()) {
                continue;
            }

            TierSetting::factory()
                ->for($shop)
                ->percentage($percentage)
                ->create(['tag' => $tag]);
        }
    }
}
