import { Badge, Banner, Card, InlineStack, Text } from '@shopify/polaris';
import { reconnectAction } from '../lib/api';
import { dateTime } from '../lib/format';
import type { CheckoutStatus as Status } from '../lib/types';

interface Props {
    /** Null until Shopify answers. */
    status: Status | null;
    error: Error | null;
}

// Whether the saved tiers are live at checkout. Nothing while Shopify hasn't
// answered yet: an empty space is better than a guess.
export default function CheckoutStatus({ status, error }: Props) {
    if (error) {
        return (
            <Banner
                tone="warning"
                title="Couldn't check the discount at checkout"
                action={reconnectAction(error)}
            >
                <p>{error.message}</p>
            </Banner>
        );
    }

    switch (status?.state) {
        case 'active':
            return (
                <Card>
                    <InlineStack gap="200" blockAlign="center">
                        <Badge tone="success">Active</Badge>
                        <Text as="p">
                            Checkout is up to date.
                            {status.synced_at && ` Last updated ${dateTime(status.synced_at)}.`}
                        </Text>
                    </InlineStack>
                </Card>
            );
        case 'inactive':
            return (
                <Banner tone="warning" title="Wholesale customers are paying full price">
                    <p>The “Wholesale tiers” discount is switched off in Discounts.</p>
                </Banner>
            );
        case 'missing':
            return (
                <Banner tone="warning" title="Wholesale customers are paying full price">
                    <p>The “Wholesale tiers” discount was deleted. Save to create it again.</p>
                </Banner>
            );
        case 'never_synced':
            return (
                <Banner tone="info">
                    <p>Checkout has not been set up yet. Save your tiers to switch it on.</p>
                </Banner>
            );
        default:
            return null;
    }
}
