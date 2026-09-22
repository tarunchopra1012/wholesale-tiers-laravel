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
        throw new Error(body?.message ?? `Request failed with status ${response.status}.`);
    }

    return body;
}
