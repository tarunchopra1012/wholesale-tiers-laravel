import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';

interface ApiState<T> {
    data: T | null;
    error: Error | null;
    loading: boolean;
}

/**
 * Loads `path` from our API and keeps the answer: { data, error, loading,
 * reload }. `data` is the response body, which the caller names as T. `error` is the Error itself, not
 * just its message: it may carry the way to reconnect the store.
 *
 * A new path is a new question, so the old answer and error are dropped: a
 * failed request can't leave the previous product's prices on screen.
 * reload() asks the same question again, and keeps the old answer showing
 * until the new one arrives. Pass null while there is nothing to load yet.
 */
export function useApi<T>(path: string | null): ApiState<T> & { reload: () => void } {
    const [state, setState] = useState<ApiState<T>>({
        data: null,
        error: null,
        loading: path !== null,
    });
    const [reloads, setReloads] = useState(0);
    const loadedPath = useRef(path);

    useEffect(() => {
        if (path === null) {
            return;
        }

        // Switching filters or products quickly can bring answers back out
        // of order. Each run drops its result once a newer run has started,
        // so a slow "Gold" answer can't overwrite the "Silver" one.
        let ignore = false;

        const pathChanged = loadedPath.current !== path;
        loadedPath.current = path;

        setState((current) =>
            pathChanged ? { data: null, error: null, loading: true } : { ...current, loading: true },
        );

        api<T>(path)
            .then((body) => {
                if (!ignore) setState({ data: body, error: null, loading: false });
            })
            .catch((e: unknown) => {
                // api() and fetch() only ever throw Errors.
                const error = e instanceof Error ? e : new Error(String(e));

                if (!ignore) setState((current) => ({ ...current, error, loading: false }));
            });

        return () => {
            ignore = true;
        };
    }, [path, reloads]);

    return { ...state, reload: () => setReloads((count) => count + 1) };
}
