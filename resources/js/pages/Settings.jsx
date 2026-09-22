import {
    Banner,
    BlockStack,
    Box,
    Button,
    Card,
    FormLayout,
    InlineStack,
    Page,
    Select,
    SkeletonBodyText,
    Text,
    TextField,
    Toast,
} from '@shopify/polaris';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';

const TYPE_OPTIONS = [
    { label: 'Percentage off', value: 'percentage' },
    { label: 'Fixed amount off', value: 'fixed' },
];

// The fields that show their own error. Any other 422 key — a tag that no
// longer exists, say — has nowhere to show, so it goes in the banner.
const FIELD_ERROR = /^tiers\.\d+\.discount_(type|value)$/;

export default function Settings() {
    const navigate = useNavigate();
    const [tiers, setTiers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [toast, setToast] = useState(null);

    useEffect(() => {
        // Drops the answer if the page is left before it arrives.
        let ignore = false;

        api('/tiers')
            .then((body) => {
                if (!ignore) setTiers(body.data);
            })
            .catch((e) => {
                if (!ignore) setLoadError(e.message);
            })
            .finally(() => {
                if (!ignore) setLoading(false);
            });

        return () => {
            ignore = true;
        };
    }, []);

    function change(index, field, value) {
        setTiers((current) =>
            current.map((tier, i) => (i === index ? { ...tier, [field]: value } : tier)),
        );
    }

    async function save() {
        setSaving(true);
        setSaveError(null);
        setFieldErrors({});

        try {
            const body = await api('/tiers', {
                method: 'PUT',
                body: JSON.stringify({ tiers }),
            });
            // What the server stored, e.g. "25" comes back as "25.00".
            setTiers(body.data);
            setToast('Tiers saved');
        } catch (e) {
            if (e.status !== 422) {
                setSaveError(e.message);
                return;
            }

            setFieldErrors(e.errors);

            const unplaced = Object.entries(e.errors).filter(([key]) => !FIELD_ERROR.test(key));
            if (unplaced.length > 0) {
                setSaveError(unplaced.map(([, messages]) => messages[0]).join(' '));
            }
        } finally {
            setSaving(false);
        }
    }

    // Laravel sends a list per field; the first message is enough.
    const fieldError = (key) => fieldErrors[key]?.[0];

    return (
        <Page
            title="Settings"
            backAction={{ content: 'Customers', onAction: () => navigate('/') }}
            secondaryActions={[{ content: 'Price preview', onAction: () => navigate('/preview') }]}
        >
            <BlockStack gap="400">
                {loadError && (
                    <Banner tone="critical" title="Couldn't load tiers">
                        <p>{loadError}</p>
                    </Banner>
                )}
                {saveError && (
                    <Banner
                        tone="critical"
                        title="Couldn't save tiers"
                        onDismiss={() => setSaveError(null)}
                    >
                        <p>{saveError}</p>
                    </Banner>
                )}

                {loading && (
                    <Card>
                        <SkeletonBodyText lines={4} />
                    </Card>
                )}

                {!loading && !loadError && tiers.length === 0 && (
                    <Card>
                        <Text as="p">This store has no tiers yet.</Text>
                    </Card>
                )}

                {tiers.map((tier, index) => (
                    <Card key={tier.tag}>
                        <BlockStack gap="400">
                            <Text as="h2" variant="headingMd">
                                {tier.tag}
                            </Text>
                            <FormLayout>
                                <FormLayout.Group>
                                    <Select
                                        label="Discount type"
                                        options={TYPE_OPTIONS}
                                        value={tier.discount_type}
                                        onChange={(value) => change(index, 'discount_type', value)}
                                        error={fieldError(`tiers.${index}.discount_type`)}
                                    />
                                    {/* `error` renders Polaris's InlineError under the
                                        field and marks the input invalid for screen
                                        readers. */}
                                    <TextField
                                        label="Discount"
                                        type="number"
                                        value={tier.discount_value}
                                        onChange={(value) => change(index, 'discount_value', value)}
                                        suffix={tier.discount_type === 'percentage' ? '%' : undefined}
                                        helpText={
                                            tier.discount_type === 'fixed'
                                                ? "Taken off each product's price, in your store's currency."
                                                : undefined
                                        }
                                        autoComplete="off"
                                        error={fieldError(`tiers.${index}.discount_value`)}
                                    />
                                </FormLayout.Group>
                            </FormLayout>
                        </BlockStack>
                    </Card>
                ))}

                {tiers.length > 0 && (
                    <Box paddingBlockEnd="400">
                        <InlineStack align="end">
                            <Button variant="primary" loading={saving} onClick={save}>
                                Save
                            </Button>
                        </InlineStack>
                    </Box>
                )}
            </BlockStack>

            {toast && <Toast content={toast} onDismiss={() => setToast(null)} />}
        </Page>
    );
}
