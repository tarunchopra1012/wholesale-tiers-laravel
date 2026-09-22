<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\TierSetting;
use Illuminate\Database\Seeder;

class TierSettingSeeder extends Seeder
{
    /**
     * A stand-in shop, created only when no store has installed the app
     * yet, so a fresh database still has tiers to look at.
     */
    private const FALLBACK_SHOP_DOMAIN = 'wholesale-tiers-dev.myshopify.com';

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
     * Give every installed shop whichever of the two tiers it doesn't have.
     * Safe to run again: existing tiers are left as they are.
     */
    public function run(): void
    {
        if (! Shop::query()->exists()) {
            Shop::factory()->create(['shop_domain' => self::FALLBACK_SHOP_DOMAIN]);
        }

        foreach (Shop::query()->whereNull('uninstalled_at')->get() as $shop) {
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
}
