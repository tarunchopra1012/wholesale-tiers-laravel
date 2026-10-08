import { FormLayout, Select, TextField } from '@shopify/polaris';
import type { TierField, TierValues } from '../lib/types';

interface Props {
    tier: TierValues;
    onChange: (field: TierField, value: string) => void;
    /** That field's message, if it has one. */
    error: (field: TierField) => string | undefined;
    tagHelp: string;
}

const TYPE_OPTIONS = [
    { label: 'Percentage off', value: 'percentage' },
    { label: 'Fixed amount off', value: 'fixed' },
];

// The values are Polaris Badge tones, the same list as App\Enums\BadgeTone.
const TONE_OPTIONS = [
    { label: 'Blue', value: 'info' },
    { label: 'Green', value: 'success' },
    { label: 'Yellow', value: 'attention' },
    { label: 'Orange', value: 'warning' },
    { label: 'Red', value: 'critical' },
    { label: 'Purple', value: 'magic' },
    { label: 'Grey', value: 'new' },
];

// One tier's fields, for a card on the page and for the Add tier dialog.
// `error(field)` gives that field's message, if any.
export default function TierFields({ tier, onChange, error, tagHelp }: Props) {
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
            <TextField
                label="Name"
                // Null from the server when the tier has no name.
                value={tier.name ?? ''}
                onChange={(value) => onChange('name', value)}
                helpText="Shown to customers at checkout, and here in the app. Leave it empty to show the tag."
                maxLength={60}
                autoComplete="off"
                error={error('name')}
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
            <Select
                label="Badge colour"
                options={TONE_OPTIONS}
                value={tier.badge_tone}
                onChange={(value) => onChange('badge_tone', value)}
                helpText="The colour of this tier's badge on the Customers page."
                error={error('badge_tone')}
            />
        </FormLayout>
    );
}
