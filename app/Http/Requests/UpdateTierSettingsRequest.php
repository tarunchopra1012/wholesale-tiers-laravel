<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTierSettingsRequest extends FormRequest
{
    /**
     * The session-token middleware has already decided which shop is
     * calling, and the tag rule below only accepts that shop's own tiers.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Shop $shop */
        $shop = $this->attributes->get('shop');

        return [
            'tiers' => ['required', 'array', 'min:1'],
            // Existing tiers only: this endpoint changes discounts, it
            // doesn't create tiers.
            'tiers.*.tag' => [
                'required',
                'string',
                'distinct',
                Rule::exists('tier_settings', 'tag')->where('shop_id', $shop->id),
            ],
            'tiers.*.discount_type' => ['required', Rule::enum(DiscountType::class)],
            // The limits depend on the same tier's type, which a flat rule
            // list can't see. forEach hands each tier in as $tier.
            'tiers.*.discount_value' => Rule::forEach(
                fn (mixed $value, string $attribute, array $data, mixed $tier): array => [
                    'required',
                    'numeric',
                    // The column holds two decimals, and the cast would
                    // round 12.345 without saying so.
                    'decimal:0,2',
                    ...match (is_array($tier) ? ($tier['discount_type'] ?? null) : null) {
                        DiscountType::Percentage->value => ['between:0,100'],
                        // The max is the column's: decimal(10,2).
                        DiscountType::Fixed->value => ['gt:0', 'max:99999999.99'],
                        // A missing or unknown type already fails its own rule.
                        default => [],
                    },
                ],
            ),
        ];
    }

    /**
     * Shown under the field on the Settings page, so they're worded for a
     * merchant, not a developer.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tiers.*.discount_value.between' => 'A percentage must be between 0 and 100.',
            'tiers.*.discount_value.gt' => 'A fixed discount must be more than 0.',
            'tiers.*.discount_value.decimal' => 'Use at most two decimal places.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tiers.*.tag' => 'tier',
            'tiers.*.discount_type' => 'discount type',
            'tiers.*.discount_value' => 'discount',
        ];
    }
}
