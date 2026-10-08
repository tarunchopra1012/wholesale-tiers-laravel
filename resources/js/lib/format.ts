import type { Tier } from './types';

// What a saved tier is called on screen: its name, or its tag when the
// merchant gave it none.
export function tierLabel(tier: Pick<Tier, 'name' | 'tag'>): string {
    return tier.name ?? tier.tag;
}

// For display only. Every price was worked out on the server in whole
// cents; this just turns an amount into text in the merchant's own format.
export function money(amount: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
}

// "5 Oct, 1:35 pm", in the merchant's own language and time zone.
export function dateTime(iso: string): string {
    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(iso));
}
