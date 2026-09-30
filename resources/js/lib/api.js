/**
 * Calls our own Laravel API: api('/customers?tier=wholesale-gold').
 *
 * No Authorization header here. App Bridge wraps fetch() and adds the ID
 * token to same-origin requests by itself — seen working in the browser on
 * 22 Sep 2026.
 */
export async function api(path, options = {}) {
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
        // Laravel puts the reason in `message`. The status is the fallback;
        // statusText is empty over HTTP/2, which the tunnel uses.
        const error = new Error(body?.message ?? `Request failed with status ${response.status}.`);
        error.status = response.status;
        // A 422 also lists each field's problems, keyed by the field's path,
        // e.g. { "tiers.0.discount_value": ["A percentage must be …"] }.
        error.errors = body?.errors ?? {};
        // A 403 carries this when Shopify no longer accepts the store's
        // tokens: the address that runs OAuth again. See reconnectAction().
        error.reauthorizeUrl = body?.reauthorize_url ?? null;
        throw error;
    }

    return body;
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
export function reconnectAction(error) {
    if (!error?.reauthorizeUrl) {
        return undefined;
    }

    return {
        content: 'Reconnect',
        onAction: () => window.open(error.reauthorizeUrl, '_top'),
    };
}
