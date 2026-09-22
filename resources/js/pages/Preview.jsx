import {
    Banner,
    BlockStack,
    Box,
    Card,
    DataTable,
    Page,
    Select,
    SkeletonBodyText,
    Text,
} from '@shopify/polaris';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';

export default function Preview() {
    const navigate = useNavigate();
    const [products, setProducts] = useState([]);
    const [productsLoading, setProductsLoading] = useState(true);
    const [productsError, setProductsError] = useState(null);
    const [productId, setProductId] = useState('');
    const [preview, setPreview] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState(null);

    useEffect(() => {
        let ignore = false;

        api('/products?limit=20')
            .then((body) => {
                if (ignore) return;
                setProducts(body.data);
                // Start on the first product, so there's a table straight away.
                setProductId(body.data[0]?.id ?? '');
            })
            .catch((e) => {
                if (!ignore) setProductsError(e.message);
            })
            .finally(() => {
                if (!ignore) setProductsLoading(false);
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

    return (
        <Page
            title="Price preview"
            backAction={{ content: 'Customers', onAction: () => navigate('/') }}
            secondaryActions={[{ content: 'Tier settings', onAction: () => navigate('/settings') }]}
        >
            <BlockStack gap="400">
                {productsError && (
                    <Banner tone="critical" title="Couldn't load products">
                        <p>{productsError}</p>
                    </Banner>
                )}
                {previewError && (
                    <Banner tone="critical" title="Couldn't work out the prices">
                        <p>{previewError}</p>
                    </Banner>
                )}
                <Card padding="0">
                    <Box padding="400">
                        <ProductPicker
                            loading={productsLoading}
                            products={products}
                            value={productId}
                            onChange={setProductId}
                        />
                    </Box>
                    <PriceTable loading={productsLoading || previewLoading} preview={preview} />
                </Card>
            </BlockStack>
        </Page>
    );
}

function ProductPicker({ loading, products, value, onChange }) {
    if (!loading && products.length === 0) {
        return <Text as="p">This store has no products yet.</Text>;
    }

    return (
        <Select
            label="Product"
            options={products.map((product) => ({ label: product.title, value: product.id }))}
            value={value}
            onChange={onChange}
            disabled={loading}
        />
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
