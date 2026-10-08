import {
    Badge,
    Banner,
    BlockStack,
    Box,
    Button,
    Card,
    InlineStack,
    Modal,
    Page,
    SkeletonBodyText,
    Text,
    Toast,
} from '@shopify/polaris';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import CheckoutStatus from '../components/CheckoutStatus';
import TierFields from '../components/TierFields';
import { useApi } from '../hooks/useApi';
import { api, ApiError, errorMessage } from '../lib/api';
import type { CheckoutStatus as Status, Data, Tier, TierField, TierValues } from '../lib/types';

// Laravel's 422 body: each field's messages, keyed by the field's path.
type FieldErrors = Record<string, string[]>;

const NEW_TIER: TierValues = { tag: '', name: '', discount_type: 'percentage', discount_value: '', badge_tone: 'info' };

// The fields that show their own error. Any other 422 key — a tier that was
// deleted in another window, say — has nowhere to show, so it goes in the
// banner.
const FIELD_ERROR = /^tiers\.\d+\.(tag|name|discount_type|discount_value|badge_tone)$/;

export default function Settings() {
    const navigate = useNavigate();
    const [tiers, setTiers] = useState<Tier[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [toast, setToast] = useState<string | null>(null);
    // The Add tier dialog: the form, or null while it's closed.
    const [draft, setDraft] = useState<TierValues | null>(null);
    const [adding, setAdding] = useState(false);
    const [addError, setAddError] = useState<string | null>(null);
    const [draftErrors, setDraftErrors] = useState<FieldErrors>({});
    // The tier waiting for delete to be confirmed, or null.
    const [deleting, setDeleting] = useState<Tier | null>(null);
    const [removing, setRemoving] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    // { state, synced_at } from Shopify. Its own request, so the tiers still
    // load when Shopify is slow. Every add, save and delete syncs to
    // Shopify, so each one asks for the status again.
    const checkout = useApi<Data<Status>>('/checkout-status');

    useEffect(() => {
        // Not useApi(): these tiers are then edited in place, so they are
        // this page's own state rather than a copy of the last answer.
        // Drops the answer if the page is left before it arrives.
        let ignore = false;

        api<Data<Tier[]>>('/tiers')
            .then((body) => {
                if (!ignore) setTiers(body.data);
            })
            .catch((e: unknown) => {
                if (!ignore) setLoadError(errorMessage(e));
            })
            .finally(() => {
                if (!ignore) setLoading(false);
            });

        return () => {
            ignore = true;
        };
    }, []);

    function change(index: number, field: TierField, value: string) {
        setTiers((current) =>
            current.map((tier, i) => (i === index ? { ...tier, [field]: value } : tier)),
        );
    }

    async function save() {
        setSaving(true);
        setSaveError(null);
        setFieldErrors({});

        try {
            const body = await api<Data<Tier[]>>('/tiers', {
                method: 'PUT',
                body: JSON.stringify({ tiers }),
            });
            // What the server stored, e.g. "25" comes back as "25.00".
            setTiers(body.data);
            checkout.reload();
            setToast('Tiers saved');
        } catch (e) {
            if (!(e instanceof ApiError) || e.status !== 422) {
                setSaveError(errorMessage(e));
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
            const body = await api<Data<Tier>>('/tiers', { method: 'POST', body: JSON.stringify(draft) });
            // Added at the end, not sorted in: sorting by tag would move
            // cards whose tag is being edited. A reload shows them by tag.
            // Unsaved edits on the other cards are kept.
            setTiers((current) => [...current, body.data]);
            setDraft(null);
            checkout.reload();
            setToast('Tier added');
        } catch (e) {
            if (e instanceof ApiError && e.status === 422) {
                setDraftErrors(e.errors);
            } else {
                setAddError(errorMessage(e));
            }
        } finally {
            setAdding(false);
        }
    }

    function askDelete(tier: Tier) {
        setDeleting(tier);
        setDeleteError(null);
    }

    async function remove() {
        // Only the delete dialog calls this, and it is only open for a tier.
        if (!deleting) {
            return;
        }

        setRemoving(true);
        setDeleteError(null);

        try {
            await api<null>(`/tiers/${deleting.id}`, { method: 'DELETE' });
            setTiers((current) => current.filter((tier) => tier.id !== deleting.id));
            // Field errors are keyed by position, which just shifted.
            setFieldErrors({});
            setDeleting(null);
            checkout.reload();
            setToast('Tier deleted');
        } catch (e) {
            setDeleteError(errorMessage(e));
        } finally {
            setRemoving(false);
        }
    }

    // Laravel sends a list per field; the first message is enough.
    const fieldError = (key: string): string | undefined => fieldErrors[key]?.[0];

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

                <CheckoutStatus status={checkout.data?.data ?? null} error={checkout.error} />

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
                                {/* The badge as the Customers page will show it. */}
                                <Badge tone={tier.badge_tone}>{tier.name || tier.tag || 'Untitled tier'}</Badge>
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
                        <InlineStack align="space-between" blockAlign="center" gap="400">
                            <Text as="p" tone="subdued">
                                Saved tiers apply at checkout through the “Wholesale tiers”
                                discount in your store's Discounts.
                            </Text>
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
                                onChange={(field, value) => setDraft((current) => current && { ...current, [field]: value })}
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
                title={`Delete ${deleting?.name || deleting?.tag || 'this tier'}?`}
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
