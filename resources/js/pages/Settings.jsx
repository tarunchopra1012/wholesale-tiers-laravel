import {
    Banner,
    BlockStack,
    Box,
    Button,
    Card,
    FormLayout,
    InlineStack,
    Modal,
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

const NEW_TIER = { tag: '', discount_type: 'percentage', discount_value: '' };

// The fields that show their own error. Any other 422 key — a tier that was
// deleted in another window, say — has nowhere to show, so it goes in the
// banner.
const FIELD_ERROR = /^tiers\.\d+\.(tag|discount_type|discount_value)$/;

export default function Settings() {
    const navigate = useNavigate();
    const [tiers, setTiers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [toast, setToast] = useState(null);
    // The Add tier dialog: the form, or null while it's closed.
    const [draft, setDraft] = useState(null);
    const [adding, setAdding] = useState(false);
    const [addError, setAddError] = useState(null);
    const [draftErrors, setDraftErrors] = useState({});
    // The tier waiting for delete to be confirmed, or null.
    const [deleting, setDeleting] = useState(null);
    const [removing, setRemoving] = useState(false);
    const [deleteError, setDeleteError] = useState(null);

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

    function openAdd() {
        setDraft(NEW_TIER);
        setAddError(null);
        setDraftErrors({});
    }

    async function add() {
        setAdding(true);
        setAddError(null);
        setDraftErrors({});

        try {
            const body = await api('/tiers', { method: 'POST', body: JSON.stringify(draft) });
            // Added at the end, not sorted in: sorting by tag would move
            // cards whose tag is being edited. A reload shows them by tag.
            // Unsaved edits on the other cards are kept.
            setTiers((current) => [...current, body.data]);
            setDraft(null);
            setToast('Tier added');
        } catch (e) {
            if (e.status === 422) {
                setDraftErrors(e.errors);
            } else {
                setAddError(e.message);
            }
        } finally {
            setAdding(false);
        }
    }

    function askDelete(tier) {
        setDeleting(tier);
        setDeleteError(null);
    }

    async function remove() {
        setRemoving(true);
        setDeleteError(null);

        try {
            await api(`/tiers/${deleting.id}`, { method: 'DELETE' });
            setTiers((current) => current.filter((tier) => tier.id !== deleting.id));
            // Field errors are keyed by position, which just shifted.
            setFieldErrors({});
            setDeleting(null);
            setToast('Tier deleted');
        } catch (e) {
            setDeleteError(e.message);
        } finally {
            setRemoving(false);
        }
    }

    // Laravel sends a list per field; the first message is enough.
    const fieldError = (key) => fieldErrors[key]?.[0];

    return (
        <Page
            title="Settings"
            backAction={{ content: 'Customers', onAction: () => navigate('/') }}
            primaryAction={{ content: 'Add tier', onAction: openAdd, disabled: loading }}
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
                        <Text as="p">This store has no tiers yet. Add one to price a customer tag.</Text>
                    </Card>
                )}

                {tiers.map((tier, index) => (
                    // Keyed by id: keyed by tag, the card would be rebuilt on
                    // every keystroke in the Tag field and lose focus.
                    <Card key={tier.id}>
                        <BlockStack gap="400">
                            <InlineStack align="space-between" blockAlign="center">
                                <Text as="h2" variant="headingMd">
                                    {tier.tag || 'Untitled tier'}
                                </Text>
                                <Button variant="plain" tone="critical" onClick={() => askDelete(tier)}>
                                    Delete
                                </Button>
                            </InlineStack>
                            <TierFields
                                tier={tier}
                                onChange={(field, value) => change(index, field, value)}
                                error={(field) => fieldError(`tiers.${index}.${field}`)}
                                tagHelp="Customers with this tag in Shopify get this tier. Renaming it doesn't change anyone's tags in Shopify, so customers with the old tag stop getting this tier."
                            />
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

            <Modal
                open={draft !== null}
                onClose={() => setDraft(null)}
                title="Add tier"
                primaryAction={{ content: 'Add tier', onAction: add, loading: adding }}
                secondaryActions={[{ content: 'Cancel', onAction: () => setDraft(null) }]}
            >
                <Modal.Section>
                    <BlockStack gap="400">
                        {addError && (
                            <Banner tone="critical" title="Couldn't add the tier">
                                <p>{addError}</p>
                            </Banner>
                        )}
                        {draft && (
                            <TierFields
                                tier={draft}
                                onChange={(field, value) => setDraft((current) => ({ ...current, [field]: value }))}
                                error={(field) => draftErrors[field]?.[0]}
                                tagHelp="The customer tag in Shopify, such as wholesale-bronze. Letters, numbers, hyphens and underscores only."
                            />
                        )}
                    </BlockStack>
                </Modal.Section>
            </Modal>

            <Modal
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.tag || 'this tier'}?`}
                primaryAction={{
                    content: 'Delete tier',
                    destructive: true,
                    onAction: remove,
                    loading: removing,
                }}
                secondaryActions={[{ content: 'Cancel', onAction: () => setDeleting(null) }]}
            >
                <Modal.Section>
                    <BlockStack gap="400">
                        {deleteError && (
                            <Banner tone="critical" title="Couldn't delete the tier">
                                <p>{deleteError}</p>
                            </Banner>
                        )}
                        <Text as="p">
                            Customers with this tag will be treated as Retail in this app. Their
                            tags in Shopify stay as they are. This can't be undone.
                        </Text>
                    </BlockStack>
                </Modal.Section>
            </Modal>

            {toast && <Toast content={toast} onDismiss={() => setToast(null)} />}
        </Page>
    );
}

// One tier's fields, for a card on the page and for the Add tier dialog.
// `error(field)` gives that field's message, if any.
function TierFields({ tier, onChange, error, tagHelp }) {
    return (
        <FormLayout>
            {/* `error` renders Polaris's InlineError under the field and
                marks the input invalid for screen readers. */}
            <TextField
                label="Tag"
                value={tier.tag}
                onChange={(value) => onChange('tag', value)}
                helpText={tagHelp}
                autoComplete="off"
                error={error('tag')}
            />
            <FormLayout.Group>
                <Select
                    label="Discount type"
                    options={TYPE_OPTIONS}
                    value={tier.discount_type}
                    onChange={(value) => onChange('discount_type', value)}
                    error={error('discount_type')}
                />
                <TextField
                    label="Discount"
                    type="number"
                    value={tier.discount_value}
                    onChange={(value) => onChange('discount_value', value)}
                    suffix={tier.discount_type === 'percentage' ? '%' : undefined}
                    helpText={
                        tier.discount_type === 'fixed'
                            ? "Taken off each product's price, in your store's currency."
                            : undefined
                    }
                    autoComplete="off"
                    error={error('discount_value')}
                />
            </FormLayout.Group>
        </FormLayout>
    );
}
