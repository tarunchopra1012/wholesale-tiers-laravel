import { Button, InlineStack, Text } from '@shopify/polaris';
import type { Product } from '../lib/types';

interface Props {
    loading: boolean;
    /** The store has no products at all. */
    empty: boolean;
    product: Pick<Product, 'id' | 'title'> | null;
    onPick: () => void;
}

export default function ProductHeader({ loading, empty, product, onPick }: Props) {
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
