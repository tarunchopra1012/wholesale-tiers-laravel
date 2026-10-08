import { Box, EmptyState, IndexTable, SkeletonBodyText, Text } from '@shopify/polaris';
import type { IndexTableProps } from '@shopify/polaris';
import type { Customer, Tier } from '../lib/types';
import TierBadges from './TierBadges';

interface Props {
    loading: boolean;
    error: Error | null;
    customers: Customer[];
    tiers: Tier[];
    pagination: IndexTableProps['pagination'];
}

const EMPTY_STATE_IMAGE =
    'https://cdn.shopify.com/s/files/1/0262/4071/2726/files/emptystate-files.png';

export default function CustomerList({ loading, error, customers, tiers, pagination }: Props) {
    if (loading) {
        return (
            <Box padding="400">
                <SkeletonBodyText lines={6} />
            </Box>
        );
    }

    // The banner above already says what went wrong.
    if (error) {
        return null;
    }

    if (customers.length === 0) {
        return (
            <EmptyState heading="No customers in this tier" image={EMPTY_STATE_IMAGE}>
                <p>Tag a customer in Shopify, or pick another tier.</p>
            </EmptyState>
        );
    }

    return (
        <IndexTable
            resourceName={{ singular: 'customer', plural: 'customers' }}
            itemCount={customers.length}
            selectable={false}
            pagination={pagination}
            headings={[
                { title: 'Name' },
                { title: 'Email' },
                { title: 'Tier' },
                { title: 'Location' },
            ]}
        >
            {customers.map((customer, index) => (
                <IndexTable.Row id={customer.id} key={customer.id} position={index}>
                    <IndexTable.Cell>
                        <Text as="span" fontWeight="semibold">
                            {fullName(customer)}
                        </Text>
                    </IndexTable.Cell>
                    <IndexTable.Cell>{customer.email ?? '—'}</IndexTable.Cell>
                    <IndexTable.Cell>
                        <TierBadges tags={customer.tags} tiers={tiers} />
                    </IndexTable.Cell>
                    <IndexTable.Cell>{customer.location ?? '—'}</IndexTable.Cell>
                </IndexTable.Row>
            ))}
        </IndexTable>
    );
}

function fullName({ first_name, last_name }: Customer): string {
    return [first_name, last_name].filter(Boolean).join(' ') || 'No name';
}
