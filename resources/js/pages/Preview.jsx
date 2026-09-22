import {
    Banner,
    BlockStack,
    Box,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    SkeletonBodyText,
    Text,
} from '@shopify/polaris';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';

export default function Preview() {
    const navigate = useNavigate();
    // { id, title } of the product being priced, or null before one is known.
    const [product, setProduct] = useState(null);
    const [starting, setStarting] = useState(true);
    const [startError, setStartError] = useState(null);
    const [pickerError, setPickerError] = useState(null);
    const [preview, setPreview] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState(null);

    const productId = product?.id;

    useEffect(() => {
        // Start on the store's first product by title, so there's a table
        // straight away. Any other product comes from the picker.
        let ignore = false;

        api('/products?limit=1')
            .then((body) => {
                if (!ignore) setProduct(body.data[0] ?? null);
            })
            .catch((e) => {
                if (!ignore) setStartError(e.message);
            })
            .finally(() => {
                if (!ignore) setStarting(false);
            });

        return () => {
            ignore = true;
        };
    }, []);

    useEffect(() => {
        if (!productId) {
            return;
        }

        // Same guard as on Customers: picking products quickly can bring
        // answers back out of order, and only the newest may land.
        let ignore = false;

        setPreviewLoading(true);
        setPreviewError(null);
        // Clear the old product's prices, so a failed request can't leave
        // them showing under the new product's name.
        setPreview(null);

        api(`/preview?${new URLSearchParams({ product_id: productId })}`)
            .then((body) => {
                if (!ignore) setPreview(body.data);
            })
            .catch((e) => {
                if (!ignore) setPreviewError(e.message);
            })
            .finally(() => {
                if (!ignore) setPreviewLoading(false);
            });

        return () => {
            ignore = true;
        };
    }, [productId]);

    async function pickProduct() {
        setPickerError(null);

        try {
            // Shopify's own product picker, drawn by the admin with its own
            // search and paging. It answers with the chosen products, or
            // undefined when the merchant cancels. Only the ID is used for
            // pricing: Laravel still reads the price from Shopify.
            const selection = await window.shopify.resourcePicker({
                type: 'product',
                action: 'select',
                // The preview prices the first variant, so offering a choice
                // of variant would suggest a difference that isn't there.
                filter: { variants: false },
            });

            if (selection?.[0]) {
                setProduct({ id: selection[0].id, title: selection[0].title });
            }
        } catch (e) {
            setPickerError(e.message);
        }
    }

    return (
        <Page
            title="Price preview"
            backAction={{ content: 'Customers', onAction: () => navigate('/') }}
            secondaryActions={[{ content: 'Tier settings', onAction: () => navigate('/settings') }]}
        >
            <BlockStack gap="400">
                {startError && (
                    <Banner tone="critical" title="Couldn't load products">
                        <p>{startError}</p>
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
                {previewError && (
                    <Banner tone="critical" title="Couldn't work out the prices">
                        <p>{previewError}</p>
                    </Banner>
                )}
                <Card padding="0">
                    <Box padding="400">
                        <ProductHeader
                            loading={starting}
                            empty={!starting && !startError && !product}
                            product={product}
                            onPick={pickProduct}
                        />
                    </Box>
                    <PriceTable loading={starting || previewLoading} preview={preview} />
                </Card>
            </BlockStack>
        </Page>
    );
}

function ProductHeader({ loading, empty, product, onPick }) {
    if (empty) {
        return <Text as="p">This store has no products yet.</Text>;
    }

    return (
        <InlineStack align="space-between" blockAlign="center" gap="400">
            <Text as="h2" variant="headingMd">
                {product?.title}
            </Text>
            <Button onClick={onPick} disabled={loading}>
                Choose product
            </Button>
        </InlineStack>
    );
}

function PriceTable({ loading, preview }) {
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
            tier.tag,
            discount(tier, product.currency),
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

// For display only. Every price was worked out on the server in whole
// cents; dividing by 100 here just turns them back into an amount to show.
function money(amount, currency) {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
}

function discount({ discount_type, discount_value }, currency) {
    // "25.00" → 25, so it reads "25% off", not "25.00% off".
    const value = Number(discount_value);

    return discount_type === 'percentage' ? `${value}% off` : `${money(value, currency)} off`;
}
