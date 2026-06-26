import { describe, expect, it } from 'vitest';

import { matchCountry } from './geo';

describe('matchCountry', () => {
    it('matches a plain country name to itself (normalised)', () => {
        expect(matchCountry('Spain')).toBe('spain');
        expect(matchCountry('  MEXICO ')).toBe('mexico');
    });

    it('resolves common GA4 names to the atlas name', () => {
        // Both the everyday name and the atlas feature name resolve to the same key.
        expect(matchCountry('United States')).toBe(matchCountry('United States of America'));
        expect(matchCountry('Czech Republic')).toBe(matchCountry('Czechia'));
        expect(matchCountry('Russia')).toBe(matchCountry('Russian Federation'));
    });

    it('normalises accents and punctuation so atlas + data labels meet', () => {
        // "Côte d'Ivoire" (atlas) and "Ivory Coast" (GA4) must collapse to one key.
        expect(matchCountry('Ivory Coast')).toBe(matchCountry("Côte d'Ivoire"));
        expect(matchCountry('Democratic Republic of the Congo')).toBe(matchCountry('Dem. Rep. Congo'));
    });

    it('returns null for empty / non-country values', () => {
        expect(matchCountry('')).toBeNull();
        expect(matchCountry('   ')).toBeNull();
    });
});
