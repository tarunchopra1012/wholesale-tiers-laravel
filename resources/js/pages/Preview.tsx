import { Banner, BlockStack, Box, Card, Page } from '@shopify/polaris';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import PriceTable from '../components/PriceTable';
import ProductHeader from '../components/ProductHeader';
import { useApi } from '../hooks/useApi';
import { errorMessage, reconnectAction } from '../lib/api';
import type { Data, Preview as PreviewData, Product } from '../lib/types';

export default function Preview() {
    const navigate = useNavigate();
    // { id, title } of the product chosen in the picker, or null before one is.
    const [picked, setPicked] = useState<Pick<Product, 'id' | 'title'> | null>(null);
    const [pickerError, setPickerError] = useState<string | null>(null);

    // Start on the store's first product by title, so there's a table
    // straight away. Any other product comes from the picker.
    const start = useApi<Data<Product[]>>('/products?limit=1');
    const product = picked ?? start.data?.data[0] ?? null;

    // Nothing to price until a product is known.
    const prices = useApi<Data<PreviewData>>(
        product ? `/preview?${new URLSearchParams({ product_id: product.id })}` : null,
    );

    async function pickProduct() {
        setPickerError(null);

        try {
            // Shopify's own product picker, drawn by the admin with its own
            // search and paging. It answers with the chosen products, or
            // undefined when the merchant cancels. Only the ID is used for
            // pricing: Laravel still reads the price from Shopify.
            const selection = await shopify.resourcePicker({
                type: 'product',
                action: 'select',
                // The preview prices the first variant, so offering a choice
                // of variant would suggest a difference that isn't there.
                filter: { variants: false },
            });

            if (selection?.[0]) {
                setPicked({ id: selection[0].id, title: selection[0].title });
            }
        } catch (e) {
            setPickerError(errorMessage(e));
        }
    }

    return (
        <Page
            title="Price preview"
            backAction={{ content: 'Customers', onAction: () => navigate('/') }}
            secondaryActions={[{ content: 'Tier settings', onAction: () => navigate('/settings') }]}
        >
            <BlockStack gap="400">
                {start.error && (
                    <Banner
                        tone="critical"
                        title="Couldn't load products"
                        action={reconnectAction(start.error)}
                    >
                        <p>{start.error.message}</p>
                    </Banner>
                )}
                {pickerError && (
                    <Banner
                        tone="critical"
                        title="Couldn't open the product picker"
                        onDismiss={() => setPickerError(null)}
                    >
                        <p>{pickerError}</p>
                    </Banner>
                )}
                {prices.error && (
                    <Banner
                        tone="critical"
                        title="Couldn't work out the prices"
                        action={reconnectAction(prices.error)}
                    >
                        <p>{prices.error.message}</p>
                    </Banner>
                )}
                <Card padding="0">
                    <Box padding="400">
                        <ProductHeader
                            loading={start.loading}
                            empty={!start.loading && !start.error && !product}
                            product={product}
                            onPick={pickProduct}
                        />
                    </Box>
                    <PriceTable
                        loading={start.loading || prices.loading}
                        preview={prices.data?.data ?? null}
                    />
                </Card>
            </BlockStack>
        </Page>
    );
}
