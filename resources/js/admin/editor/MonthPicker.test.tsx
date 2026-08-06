import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { MonthPicker } from './MonthPicker';

describe('MonthPicker', () => {
    it('picks a month in a single click', () => {
        // The whole point of replacing <input type="month">: one click, not three.
        const onPick = vi.fn();
        render(<MonthPicker value="2026-08" onPick={onPick} />);

        fireEvent.click(screen.getByText('mar'));

        expect(onPick).toHaveBeenCalledWith('2026-03');
    });

    it('opens on the selected month’s year, not the current one', () => {
        render(<MonthPicker value="2024-02" onPick={vi.fn()} />);

        expect(screen.getByText('2024')).toBeTruthy();
    });

    it('steps through years without changing the selection', () => {
        const onPick = vi.fn();
        render(<MonthPicker value="2026-08" onPick={onPick} />);

        fireEvent.click(screen.getByLabelText('Año anterior'));
        expect(screen.getByText('2025')).toBeTruthy();

        fireEvent.click(screen.getByText('dic'));
        expect(onPick).toHaveBeenCalledWith('2025-12');
    });
});
