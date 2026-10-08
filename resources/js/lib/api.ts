/**
 * What api() throws when Laravel answers with an error status.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
        // A 422 lists each field's problems, keyed by the field's path,
        // e.g. { "tiers.0.discount_value": ["A percentage must be …"] }.
        readonly errors: Record<string, string[]>,
        // A 403 carries this when Shopify no longer accepts the store's
        // tokens: the address that runs OAuth again. See reconnectAction().
        readonly reauthorizeUrl: string | null,
    ) {
        super(message);
    }
}

/**
 * Calls our own Laravel API: api('/customers?tier=wholesale-gold').
 *
 * No Authorization header here. App Bridge wraps fetch() and adds the ID
 * token to same-origin requests by itself — seen working in the browser on
 * 22 Sep 2026.
 *
 * T is the body the caller expects. Nothing checks it against what the
 * server really sent: the types in types.ts are kept in step by hand.
 */
export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(`/api${path}`, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...options.headers,
        },
    });

    // Not every response has a JSON body: a 204, or an HTML error page
    // from nginx or a dead tunnel.
    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(
            // Laravel puts the reason in `message`. The status is the
            // fallback; statusText is empty over HTTP/2, which the tunnel uses.
            body?.message ?? `Request failed with status ${response.status}.`,
            response.status,
            body?.errors ?? {},
            body?.reauthorize_url ?? null,
        );
    }

    return body as T;
}

/**
 * The text of whatever a catch block caught. Usually an ApiError; a request
 * that never got an answer throws fetch's own TypeError instead.
 */
export function errorMessage(error: unknown): string {
    return error instanceof Error ? error.message : String(error);
}

/**
 * The button for a Polaris Banner that shows `error`: "Reconnect" when the
 * store has to authorize the app again, and nothing for any other error.
 *
 * '_top' because Shopify's consent screen won't load inside the admin's
 * frame. With App Bridge loaded, open(url, '_top') leaves the admin and
 * loads that page in the whole tab. The URL is absolute on purpose: App
 * Bridge treats a relative one as a route inside this app.
 */
export function reconnectAction(error: Error | null) {
    if (!(error instanceof ApiError) || !error.reauthorizeUrl) {
        return undefined;
    }

    const url = error.reauthorizeUrl;

    return {
        content: 'Reconnect',
        onAction: () => {
            window.open(url, '_top');
        },
    };
}
