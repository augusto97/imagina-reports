import { describe, expect, it } from 'vitest';

import { countryForMap, countryLabel } from './countries';

describe('countryLabel', () => {
    it('turns ISO codes from Search Console and Cloudflare into names', () => {
        expect(countryLabel('col')).toBe('Colombia');
        expect(countryLabel('CO')).toBe('Colombia');
        expect(countryLabel('usa')).toBe('Estados Unidos');
    });

    it('translates the English names Google Analytics sends', () => {
        expect(countryLabel('United States')).toBe('Estados Unidos');
        expect(countryLabel('Spain')).toBe('España');
    });

    it('leaves anything else alone and names the unknown', () => {
        expect(countryLabel('Bogotá')).toBe('Bogotá');
        expect(countryLabel('zzz')).toBe('Desconocido');
    });
});

describe('countryForMap', () => {
    it('gives the atlas an English name for codes', () => {
        expect(countryForMap('mex')).toBe('Mexico');
        expect(countryForMap('Colombia')).toBe('Colombia');
    });
});
