import { useEffect, useState } from 'react';

/**
 * Reactive viewport match (no extra deps) — true while the media query holds.
 *
 * Used where a layout difference can't be expressed with Tailwind's responsive classes:
 * rendering a different tree (a bottom sheet instead of a side panel) rather than hiding
 * one with CSS, so the hidden branch never mounts.
 */
export function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() =>
        typeof window !== 'undefined' ? window.matchMedia(query).matches : false,
    );

    useEffect(() => {
        const mql = window.matchMedia(query);
        const handler = (event: MediaQueryListEvent): void => setMatches(event.matches);
        setMatches(mql.matches);
        mql.addEventListener('change', handler);

        return () => mql.removeEventListener('change', handler);
    }, [query]);

    return matches;
}

/** Below Tailwind's `lg` — where the editor switches to its one-row bar + bottom sheets. */
export const COMPACT_QUERY = '(max-width: 1023px)';

/**
 * Below Tailwind's `xl`. Desktop layout, but not enough width for a toolbar that also
 * carries the preview cluster (site + period + sync); there it moves into the overflow.
 */
export const TIGHT_BAR_QUERY = '(max-width: 1279px)';
