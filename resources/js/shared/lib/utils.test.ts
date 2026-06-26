import { describe, expect, it } from 'vitest';

import { cn } from './utils';

describe('cn', () => {
    it('joins truthy class values and drops falsy ones', () => {
        expect(cn('a', false, undefined, 'b', null)).toBe('a b');
    });

    it('lets later ir-prefixed Tailwind utilities win conflicts (twMerge with the ir- prefix)', () => {
        // The SPAs use the `ir-` prefix; cn() is configured for it, so conflicts merge.
        expect(cn('ir-p-2', 'ir-p-4')).toBe('ir-p-4');
        expect(cn('ir-max-w-lg', 'ir-max-w-5xl')).toBe('ir-max-w-5xl');
    });
});
