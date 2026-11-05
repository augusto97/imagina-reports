import { describe, expect, it } from 'vitest';

import { columnLabel, formatColumnValue } from './columns';

describe('columnLabel', () => {
    it('translates the keys connectors hand back', () => {
        expect(columnLabel('clicks')).toBe('Clics');
        expect(columnLabel('screenPageViews')).toBe('Vistas');
        expect(columnLabel('items_sold')).toBe('Vendidos');
    });

    it('humanises unknown keys', () => {
        expect(columnLabel('avg_order_value')).toBe('Avg order value');
        expect(columnLabel('newSignups')).toBe('New Signups');
    });
});

describe('formatColumnValue', () => {
    it('reads rates as percentages, never as fractions', () => {
        expect(formatColumnValue('ctr', 5.4, 'es')).toBe('5,4 %');
        // A 0.5 % CTR must not turn into 50 %.
        expect(formatColumnValue('ctr', 0.5, 'es')).toBe('0,5 %');
    });

    it('gives a position one decimal and leaves other columns alone', () => {
        expect(formatColumnValue('position', 3, 'es')).toBe('3,0');
        expect(formatColumnValue('clicks', 1200, 'es')).toBeNull();
    });
});
