import { describe, expect, it } from 'vitest';

import { cn } from './utils';

describe('cn', () => {
    it('joins truthy class values and drops falsy ones', () => {
        expect(cn('a', false, undefined, 'b', null)).toBe('a b');
    });

    it('lets later Tailwind utilities win conflicts (twMerge)', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });
});
