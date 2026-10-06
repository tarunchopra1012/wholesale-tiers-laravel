<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TierSetting;

/**
 * A shop's tiers as the JSON the checkout Function reads from the tier
 * discount's metafield. Pure: no database, no HTTP.
 *
 * This shape is the whole contract between Laravel and the Function, so a
 * change here needs the same change in extensions/wholesale-tier-discount.
 */
final readonly class TierDiscountConfig
{
    /**
     * `tags` repeats the tiers' tags on purpose: Shopify fills the
     * Function's input query variable from that key, to ask which of them
     * the customer has.
     *
     * @param  iterable<TierSetting>  $tiers
     * @return array{tags: list<string>, tiers: list<array{tag: string, name: string, type: string, value: string}>}
     */
    public function build(iterable $tiers): array
    {
        $config = ['tags' => [], 'tiers' => []];

        foreach ($tiers as $tier) {
            $config['tags'][] = $tier->tag;
            $config['tiers'][] = [
                'tag' => $tier->tag,
                // What checkout shows the customer. Always a string: a tier
                // without a name goes out under its tag.
                'name' => $tier->name ?? $tier->tag,
                'type' => $tier->discount_type->value,
                // The decimal:2 cast's string, e.g. "20.00": never a float.
                'value' => (string) $tier->discount_value,
            ];
        }

        return $config;
    }
}
