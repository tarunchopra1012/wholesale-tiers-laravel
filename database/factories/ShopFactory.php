<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_domain' => fake()->unique()->domainWord().'.myshopify.com',
            'access_token' => 'shpat_'.Str::lower(Str::random(32)),
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_'.Str::lower(Str::random(32)),
            'refresh_token_expires_at' => now()->addDays(90),
            'scopes' => 'read_customers,read_products',
            'installed_at' => now(),
            'uninstalled_at' => null,
        ];
    }

    /**
     * Indicate that the merchant has uninstalled the app.
     *
     * There is no soft delete here: the row and its tiers stay, and a
     * reinstall clears the timestamp again.
     */
    public function uninstalled(): static
    {
        return $this->state(fn (array $attributes) => [
            'uninstalled_at' => now(),
        ]);
    }
}
