import { Banner, BlockStack, Box, Card, Page, Select } from '@shopify/polaris';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import CustomerList from '../components/CustomerList';
import { useApi } from '../hooks/useApi';
import { reconnectAction } from '../lib/api';
import { tierLabel } from '../lib/format';
import type { CustomersPage, Data, Tier } from '../lib/types';

// No "Retail" option: the API filters by one tag, and untagged customers
// have none to filter on.
const ALL_CUSTOMERS = { label: 'All customers', value: '' };

export default function Customers() {
    const navigate = useNavigate();
    const [tier, setTier] = useState('');
    // The cursor each visited page started after, oldest first; the first
    // page starts after nothing. Shopify only hands out a cursor for the
    // next page, so Previous steps back through this list instead of asking
    // Shopify for the page before.
    const [cursors, setCursors] = useState<Array<string | null>>([null]);

    const after = cursors[cursors.length - 1];

    // The shop's tiers, as the Settings page saved them. They decide both
    // what can be filtered on and which tags get a badge.
    const tiersLoad = useApi<Data<Tier[]>>('/tiers');
    const tiers = tiersLoad.data?.data ?? [];

    const params = new URLSearchParams();
    if (tier) params.set('tier', tier);
    if (after) params.set('after', after);
    const query = params.toString() ? `?${params}` : '';

    // Loads again whenever the filter or the page changes the path.
    const page = useApi<CustomersPage>(`/customers${query}`);
    const customers = page.data?.data ?? [];
    const pageInfo = page.data?.page_info;

    function changeTier(value: string) {
        setTier(value);
        // A cursor only means something within the search it came from.
        setCursors([null]);
    }

    const pagination = {
        hasPrevious: cursors.length > 1,
        onPrevious: () => setCursors((current) => current.slice(0, -1)),
        hasNext: pageInfo?.has_next_page ?? false,
        onNext: () => setCursors((current) => [...current, pageInfo?.end_cursor ?? null]),
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
                {page.error && (
                    <Banner
                        tone="critical"
                        title="Couldn't load customers"
                        action={reconnectAction(page.error)}
                    >
                        <p>{page.error.message}</p>
                    </Banner>
                )}
                {tiersLoad.error && (
                    <Banner tone="warning" title="Couldn't load this store's tiers">
                        <p>
                            The customers below are listed without their tiers.{' '}
                            {tiersLoad.error.message}
                        </p>
                    </Banner>
                )}
                <Card padding="0">
                    <Box padding="400">
                        <Select
                            label="Tier"
                            options={[
                                ALL_CUSTOMERS,
                                ...tiers.map((t) => ({ label: tierLabel(t), value: t.tag })),
                            ]}
                            value={tier}
                            onChange={changeTier}
                        />
                    </Box>
                    <CustomerList
                        loading={page.loading}
                        error={page.error}
                        customers={customers}
                        tiers={tiers}
                        pagination={pagination}
                    />
                </Card>
            </BlockStack>
        </Page>
    );
}
