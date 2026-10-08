import { Badge, InlineStack } from '@shopify/polaris';
import { tierLabel } from '../lib/format';
import type { Tier } from '../lib/types';

interface Props {
    /** One customer's tags. */
    tags: string[];
    tiers: Tier[];
}

export default function TierBadges({ tags, tiers }: Props) {
    // Shopify's tag search ignores case — tag:WHOLESALE-GOLD finds a
    // customer tagged wholesale-gold, checked on the dev store — so a tier
    // whose tag is saved in another case must still badge its customers.
    const lowercased = tags.map((tag) => tag.toLowerCase());
    const matches = tiers.filter((tier) => lowercased.includes(tier.tag.toLowerCase()));

    if (matches.length === 0) {
        return 'Retail';
    }

    // One badge per matching tier, rather than picking a winner: which tier
    // should win is a pricing question, and a percentage and a fixed amount
    // can't be compared without a product's price.
    return (
        <InlineStack gap="100">
            {matches.map((tier) => (
                <Badge key={tier.id} tone={tier.badge_tone}>
                    {tierLabel(tier)}
                </Badge>
            ))}
        </InlineStack>
    );
}
