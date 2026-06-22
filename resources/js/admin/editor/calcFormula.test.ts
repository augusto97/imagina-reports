import { describe, expect, it } from 'vitest';

import { validateFormula } from './calcFormula';

const known = new Set(['ga4.sessions', 'woocommerce.orders', 'woocommerce.revenue']);

describe('validateFormula', () => {
    it('accepts arithmetic over known metrics', () => {
        expect(validateFormula('woocommerce.revenue / woocommerce.orders', known).ok).toBe(true);
        expect(validateFormula('(ga4.sessions + 1) * 100', known).ok).toBe(true);
        expect(validateFormula('42', known).ok).toBe(true);
    });

    it('rejects unknown metrics', () => {
        const result = validateFormula('ga4.sessions / cloudflare.requests', known);
        expect(result.ok).toBe(false);
        expect(result.error).toContain('cloudflare.requests');
    });

    it('rejects unbalanced parentheses and empty input', () => {
        expect(validateFormula('(ga4.sessions + 1', known).ok).toBe(false);
        expect(validateFormula('', known).ok).toBe(false);
    });

    it('rejects invalid characters', () => {
        expect(validateFormula('ga4.sessions % 2', known).ok).toBe(false);
    });
});
