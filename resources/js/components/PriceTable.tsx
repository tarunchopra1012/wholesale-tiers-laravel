import { Box, DataTable, SkeletonBodyText } from '@shopify/polaris';
import { money, tierLabel } from '../lib/format';
import type { Preview, Tier } from '../lib/types';

interface Props {
    loading: boolean;
    preview: Preview | null;
}

export default function PriceTable({ loading, preview }: Props) {
    if (loading) {
        return (
            <Box padding="400">
                <SkeletonBodyText lines={4} />
            </Box>
        );
    }

    // Nothing picked yet, or the request failed and the banner says why.
    if (!preview) {
        return null;
    }

    const { product, tiers } = preview;

    const rows = [
        // Customers without a tier tag pay the base price. The
        // Customers page calls them Retail too.
        ['Retail', '—', money(product.price_cents / 100, product.currency)],
        ...tiers.map((tier) => [
            tierLabel(tier),
            discount(tier, product.currency),
            // Worked out on the server in whole cents; dividing by 100 just
            // turns them back into an amount to show.
            money(tier.final_price_cents / 100, product.currency),
        ]),
    ];

    return (
        <DataTable
            columnContentTypes={['text', 'text', 'numeric']}
            headings={['Tier', 'Discount', 'Final price']}
            rows={rows}
        />
    );
}

function discount({ discount_type, discount_value }: Tier, currency: string): string {
    // "25.00" → 25, so it reads "25% off", not "25.00% off".
    const value = Number(discount_value);

    return discount_type === 'percentage' ? `${value}% off` : `${money(value, currency)} off`;
}
