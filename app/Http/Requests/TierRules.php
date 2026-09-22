<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Models\Shop;
use Illuminate\Validation\Rule;

/**
 * The rules for one tier's fields, shared by creating and editing, so the
 * two can't drift apart — a tier created at 150% that could then never be
 * saved again, say.
 */
final class TierRules
{
    /**
     * Tag-safe characters only. A tier's tag is pasted into Shopify's search
     * syntax as tag:<tag> by the Customers filter, which checks against this
     * same pattern. \z rather than $, for the same reason as in ShopDomain.
     */
    public const TAG_PATTERN = '/^[A-Za-z0-9_-]+\z/';

    /**
     * @return list<mixed>
     */
    public static function tag(Shop $shop, ?int $ignoreId = null): array
    {
        return [
            'required',
            'string',
            // The column is a varchar(255).
            'max:255',
            'regex:'.self::TAG_PATTERN,
            // Among this shop's tiers only; another shop may use the same
            // tag. MySQL compares case-insensitively here, as the unique
            // index does, so "Gold" and "gold" count as the same tag.
            Rule::unique('tier_settings', 'tag')->where('shop_id', $shop->id)->ignore($ignoreId),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function discountType(): array
    {
        return ['required', Rule::enum(DiscountType::class)];
    }

    /**
     * The limits depend on the same tier's type, so the caller passes it in.
     *
     * @return list<string>
     */
    public static function discountValue(mixed $type): array
    {
        return [
            'required',
            'numeric',
            // The column holds two decimals, and the cast would round 12.345
            // without saying so.
            'decimal:0,2',
            ...match ($type) {
                DiscountType::Percentage->value => ['between:0,100'],
                // The max is the column's: decimal(10,2).
                DiscountType::Fixed->value => ['gt:0', 'max:99999999.99'],
                // A missing or unknown type already fails its own rule.
                default => [],
            },
        ];
    }

    /**
     * Shown under the field on the Settings page, so they're worded for a
     * merchant, not a developer. $prefix is where the tier sits in the
     * request, e.g. "tiers.*." for a list of them.
     *
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        return [
            "{$prefix}tag.regex" => 'Use only letters, numbers, hyphens and underscores.',
            "{$prefix}tag.unique" => 'Another tier already uses this tag.',
            "{$prefix}tag.distinct" => "Two tiers can't use the same tag.",
            "{$prefix}discount_value.between" => 'A percentage must be between 0 and 100.',
            "{$prefix}discount_value.gt" => 'A fixed discount must be more than 0.',
            "{$prefix}discount_value.decimal" => 'Use at most two decimal places.',
        ];
    }
}
