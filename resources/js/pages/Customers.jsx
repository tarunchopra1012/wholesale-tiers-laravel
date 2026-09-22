import {
    Badge,
    Banner,
    BlockStack,
    Box,
    Card,
    EmptyState,
    IndexTable,
    Page,
    Select,
    SkeletonBodyText,
    Text,
} from '@shopify/polaris';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';

// The tags the app treats as tiers, best first. Hard-coded until the
// Settings page reads them from tier_settings.
const TIERS = {
    'wholesale-gold': { label: 'Gold', tone: 'success' },
    'wholesale-silver': { label: 'Silver', tone: 'info' },
};

// No "Retail" option: the API filters by one tag, and untagged customers
// have none to filter on.
const TIER_OPTIONS = [
    { label: 'All customers', value: '' },
    { label: 'Gold', value: 'wholesale-gold' },
    { label: 'Silver', value: 'wholesale-silver' },
];

const EMPTY_STATE_IMAGE =
    'https://cdn.shopify.com/s/files/1/0262/4071/2726/files/emptystate-files.png';

export default function Customers() {
    const navigate = useNavigate();
    const [tier, setTier] = useState('');
    const [customers, setCustomers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        // Switching filters quickly can bring answers back out of order.
        // Each run drops its result once a newer run has started, so a slow
        // "Gold" answer can't overwrite the "Silver" one.
        let ignore = false;

        setLoading(true);
        setError(null);

        const query = tier ? `?${new URLSearchParams({ tier })}` : '';

        api(`/customers${query}`)
            .then((body) => {
                if (!ignore) setCustomers(body.data);
            })
            .catch((e) => {
                if (!ignore) setError(e.message);
            })
            .finally(() => {
                if (!ignore) setLoading(false);
            });

        return () => {
            ignore = true;
        };
    }, [tier]);

    return (
        <Page
            title="Customers"
            secondaryActions={[
                { content: 'Tier settings', onAction: () => navigate('/settings') },
                { content: 'Price preview', onAction: () => navigate('/preview') },
            ]}
        >
            <BlockStack gap="400">
                {error && (
                    <Banner tone="critical" title="Couldn't load customers">
                        <p>{error}</p>
                    </Banner>
                )}
                <Card padding="0">
                    <Box padding="400">
                        <Select
                            label="Tier"
                            options={TIER_OPTIONS}
                            value={tier}
                            onChange={setTier}
                        />
                    </Box>
                    <CustomerList loading={loading} error={error} customers={customers} />
                </Card>
            </BlockStack>
        </Page>
    );
}

function CustomerList({ loading, error, customers }) {
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
                        <TierBadge tags={customer.tags} />
                    </IndexTable.Cell>
                    <IndexTable.Cell>{customer.location ?? '—'}</IndexTable.Cell>
                </IndexTable.Row>
            ))}
        </IndexTable>
    );
}

function TierBadge({ tags }) {
    // First match in TIERS order, so gold wins if a customer has both tags.
    const tag = Object.keys(TIERS).find((t) => tags.includes(t));

    if (!tag) {
        return 'Retail';
    }

    return <Badge tone={TIERS[tag].tone}>{TIERS[tag].label}</Badge>;
}

function fullName({ first_name, last_name }) {
    return [first_name, last_name].filter(Boolean).join(' ') || 'No name';
}
