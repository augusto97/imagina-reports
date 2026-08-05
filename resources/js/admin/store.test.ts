import { describe, expect, it } from 'vitest';

import { platformHash, platformTabFromHash, viewFromHash, viewHash } from './store';

function withHash(hash: string): void {
    window.location.hash = hash;
}

describe('admin routing', () => {
    it('reads the agency section from the hash', () => {
        withHash('#/reports');
        expect(viewFromHash()).toBe('reports');
        expect(viewHash('reports')).toBe('#/reports');
    });

    it('reads the platform section from its own namespace', () => {
        withHash('#/platform/agencies');
        expect(platformTabFromHash()).toBe('agencies');
        expect(platformHash('agencies')).toBe('#/platform/agencies');
    });

    it('keeps the two namespaces apart', () => {
        // The bug this guards: leaving an impersonated agency used to leave that agency's
        // hash in the address bar while the platform panel was on screen. Neither parser
        // may claim the other's address, so whichever shell mounts corrects it.
        withHash('#/platform/agencies');
        expect(viewFromHash()).toBeNull();

        withHash('#/reports');
        expect(platformTabFromHash()).toBeNull();
    });

    it('rejects unknown sections instead of guessing', () => {
        withHash('#/platform/nope');
        expect(platformTabFromHash()).toBeNull();

        withHash('#/nope');
        expect(viewFromHash()).toBeNull();
    });

    it('ignores a query string on the hash', () => {
        withHash('#/platform/plans?foo=1');
        expect(platformTabFromHash()).toBe('plans');
    });
});
