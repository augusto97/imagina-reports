import { describe, expect, it } from 'vitest';

import { hexToHslString } from './color';

describe('hexToHslString', () => {
    it('converts primary colours to the "H S% L%" token triple', () => {
        expect(hexToHslString('#ff0000')).toBe('0 100% 50%');
        expect(hexToHslString('#00ff00')).toBe('120 100% 50%');
        expect(hexToHslString('#0000ff')).toBe('240 100% 50%');
    });

    it('handles greyscale (no saturation) and is hash-optional + case-insensitive', () => {
        expect(hexToHslString('#000000')).toBe('0 0% 0%');
        expect(hexToHslString('#ffffff')).toBe('0 0% 100%');
        expect(hexToHslString('FFFFFF')).toBe('0 0% 100%');
    });

    it('returns null for malformed input', () => {
        expect(hexToHslString('not-a-colour')).toBeNull();
        expect(hexToHslString('#fff')).toBeNull(); // shorthand is not supported
    });
});
