import { useEffect, useRef } from 'react';

/**
 * Two-way binding between a piece of navigation state and the URL hash.
 *
 * Two behaviours worth stating, because both were previously wrong somewhere in the app:
 *
 * - **Writes push a history entry.** Syncing with `replaceState` meant the browser's Back
 *   button never walked the sections you had just visited — it left the app entirely.
 *   The first sync of a mount still *replaces*, so correcting a stale address (the hash a
 *   different shell left behind) doesn't manufacture a bogus history entry.
 * - **Back, Forward and hand-edited addresses are read back.** `popstate` as well as
 *   `hashchange`, since a `pushState` navigation between two hashes fires only the former.
 *
 * `toHash`, `fromHash` and `onNavigate` must be stable references (module-level functions,
 * `useCallback`, or a `useState`/store setter).
 */
export function useHashRoute<T extends string>(
    value: T,
    toHash: (value: T) => string,
    fromHash: () => T | null,
    onNavigate: (value: T) => void,
): void {
    const mounted = useRef(false);

    useEffect(() => {
        const target = toHash(value);
        if (window.location.hash !== target) {
            if (mounted.current) {
                window.history.pushState(null, '', target);
            } else {
                window.history.replaceState(null, '', target);
            }
        }
        mounted.current = true;
    }, [value, toHash]);

    useEffect(() => {
        const sync = (): void => {
            const next = fromHash();
            if (next !== null && next !== value) {
                onNavigate(next);
            }
        };
        window.addEventListener('hashchange', sync);
        window.addEventListener('popstate', sync);

        return () => {
            window.removeEventListener('hashchange', sync);
            window.removeEventListener('popstate', sync);
        };
    }, [fromHash, onNavigate, value]);
}
