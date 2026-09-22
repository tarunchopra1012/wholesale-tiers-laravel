import {
    Badge,
    Banner,
    BlockStack,
    Box,
    Card,
    EmptyState,
    IndexTable,
    InlineStack,
    Page,
    Select,
    SkeletonBodyText,
    Text,
} from '@shopify/polaris';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';

// No "Retail" option: the API filters by one tag, and untagged customers
// have none to filter on.
const ALL_CUSTOMERS = { label: 'All customers', value: '' };

const EMPTY_STATE_IMAGE =
    'https://cdn.shopify.com/s/files/1/0262/4071/2726/files/emptystate-files.png';

export default function Customers() {
    const navigate = useNavigate();
    const [tier, setTier] = useState('');
    // The cursor each visited page started after, oldest first; the first
    // page starts after nothing. Shopify only hands out a cursor for the
    // next page, so Previous steps back through this list instead of asking
    // Shopify for the page before.
    const [cursors, setCursors] = useState([null]);
    const [customers, setCustomers] = useState([]);
    const [hasNextPage, setHasNextPage] = useState(false);
    const [endCursor, setEndCursor] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    // The shop's tiers, as the Settings page saved them. They decide both
    // what can be filtered on and which tags get a badge.
    const [tiers, setTiers] = useState([]);
    const [tiersError, setTiersError] = useState(null);

    const after = cursors[cursors.length - 1];

    useEffect(() => {
        let ignore = false;

        api('/tiers')
            .then((body) => {
                if (!ignore) setTiers(body.data);
            })
            .catch((e) => {
                if (!ignore) setTiersError(e.message);
            });

        return () => {
            ignore = true;
        };
    }, []);

    useEffect(() => {
        // Switching filters or pages quickly can bring answers back out of
        // order. Each run drops its result once a newer run has started, so
        // a slow "Gold" answer can't overwrite the "Silver" one.
        let ignore = false;

        setLoading(true);
        setError(null);

        const params = new URLSearchParams();
        if (tier) params.set('tier', tier);
        if (after) params.set('after', after);
        const query = params.toString() ? `?${params}` : '';

        api(`/customers${query}`)
            .then((body) => {
                if (ignore) return;
                setCustomers(body.data);
                setHasNextPage(body.page_info.has_next_page);
                setEndCursor(body.page_info.end_cursor);
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
    }, [tier, after]);

    function changeTier(value) {
        setTier(value);
        // A cursor only means something within the search it came from.
        setCursors([null]);
    }

    const pagination = {
        hasPrevious: cursors.length > 1,
        onPrevious: () => setCursors((current) => current.slice(0, -1)),
        hasNext: hasNextPage,
        onNext: () => setCursors((current) => [...current, endCursor]),
        label: `Page ${cursors.length}`,
    };

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
                {tiersError && (
                    <Banner tone="warning" title="Couldn't load this store's tiers">
                        <p>
                            The customers below are listed without their tiers. {tiersError}
                        </p>
                    </Banner>
                )}
                <Card padding="0">
                    <Box padding="400">
                        <Select
                            label="Tier"
                            options={[
                                ALL_CUSTOMERS,
                                ...tiers.map((t) => ({ label: t.tag, value: t.tag })),
                            ]}
                            value={tier}
                            onChange={changeTier}
                        />
                    </Box>
                    <CustomerList
                        loading={loading}
                        error={error}
                        customers={customers}
                        tiers={tiers}
                        pagination={pagination}
                    />
                </Card>
            </BlockStack>
        </Page>
    );
}

function CustomerList({ loading, error, customers, tiers, pagination }) {
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

function TierBadges({ tags, tiers }) {
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
                <Badge key={tier.id} tone="info">
                    {tier.tag}
                </Badge>
            ))}
        </InlineStack>
    );
}

function fullName({ first_name, last_name }) {
    return [first_name, last_name].filter(Boolean).join(' ') || 'No name';
}
