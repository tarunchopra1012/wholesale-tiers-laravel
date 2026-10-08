// What our Laravel API answers with: one type per API Resource in
// app/Http/Resources. Change a Resource, and change its type here.

export type DiscountType = 'percentage' | 'fixed';

/** A Polaris Badge tone. The same list as App\Enums\BadgeTone. */
export type BadgeTone = 'info' | 'success' | 'attention' | 'warning' | 'critical' | 'magic' | 'new';

/** TierSettingResource. */
export interface Tier {
    id: number;
    tag: string;
    /** Null when the merchant gave none; the pages then show the tag. */
    name: string | null;
    discount_type: DiscountType;
    /** A decimal string such as "25.00", exactly as stored. */
    discount_value: string;
    badge_tone: BadgeTone;
}

/** The fields of a tier the merchant can edit: every one but its id. */
export type TierValues = Omit<Tier, 'id'>;
export type TierField = keyof TierValues;

/** CustomerResource. */
export interface Customer {
    id: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    tags: string[];
    location: string | null;
}

/** ProductResource. */
export interface Product {
    id: string;
    title: string;
    price_cents: number;
    currency: string;
}

/** PreviewResource: each tier as GET /api/tiers shows it, plus its price. */
export interface Preview {
    product: Product;
    tiers: Array<Tier & { final_price_cents: number }>;
}

/** CheckoutStatusResource. The states are App\Enums\CheckoutState. */
export interface CheckoutStatus {
    state: 'active' | 'inactive' | 'missing' | 'never_synced';
    /** ISO 8601, in UTC. Null before the first sync. */
    synced_at: string | null;
}

/** Laravel wraps every Resource in { data: … }. */
export interface Data<T> {
    data: T;
}

/** GET /api/customers: one page, and where the next one starts. */
export interface CustomersPage extends Data<Customer[]> {
    page_info: {
        has_next_page: boolean;
        end_cursor: string | null;
    };
}
