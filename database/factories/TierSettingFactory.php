<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\Shop;
use App\Models\TierSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TierSetting>
 */
class TierSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'tag' => 'wholesale-'.fake()->unique()->word(),
            'discount_type' => DiscountType::Percentage,
            'discount_value' => fake()->randomFloat(2, 5, 40),
        ];
    }

    /**
     * A percentage off the product price.
     */
    public function percentage(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => DiscountType::Percentage,
            'discount_value' => $value,
        ]);
    }

    /**
     * A flat amount off the product price, in the shop's currency.
     */
    public function fixed(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => DiscountType::Fixed,
            'discount_value' => $value,
        ]);
    }
}
