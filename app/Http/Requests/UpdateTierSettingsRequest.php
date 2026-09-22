<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTierSettingsRequest extends FormRequest
{
    /**
     * The session-token middleware has already decided which shop is
     * calling, and the id rule below only accepts that shop's own tiers.
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
            // Matched by id, not tag, because the tag is one of the things
            // that can change. Existing tiers only: this endpoint edits,
            // POST /api/tiers creates.
            'tiers.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('tier_settings', 'id')->where('shop_id', $shop->id),
            ],
            // Unique against every other tier's tag as it is now, including
            // the others in this request. So swapping two tags in one save
            // is refused rather than tripping the unique index halfway
            // through; it takes two saves.
            'tiers.*.tag' => Rule::forEach(
                fn (mixed $value, string $attribute, array $data, mixed $tier): array => [
                    ...TierRules::tag($shop, self::ownId($tier)),
                    'distinct:ignore_case',
                ],
            ),
            'tiers.*.discount_type' => TierRules::discountType(),
            // forEach hands each tier in as $tier, so the limits can follow
            // that tier's own type.
            'tiers.*.discount_value' => Rule::forEach(
                fn (mixed $value, string $attribute, array $data, mixed $tier): array => TierRules::discountValue(
                    is_array($tier) ? ($tier['discount_type'] ?? null) : null,
                ),
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...TierRules::messages('tiers.*.'),
            // Shown in the page's banner, since no field is wrong.
            'tiers.*.id.exists' => 'One of these tiers has been deleted, perhaps in another window. Reload the page to see the current tiers.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tiers.*.id' => 'tier',
            'tiers.*.tag' => 'tag',
            'tiers.*.discount_type' => 'discount type',
            'tiers.*.discount_value' => 'discount',
        ];
    }

    /**
     * The tier's own id, so its current tag doesn't count as taken. Only a
     * real integer: anything else fails the id rule anyway.
     */
    private static function ownId(mixed $tier): ?int
    {
        $id = is_array($tier) ? ($tier['id'] ?? null) : null;

        return is_int($id) ? $id : null;
    }
}
