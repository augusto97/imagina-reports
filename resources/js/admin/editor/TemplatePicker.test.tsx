import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { ReportTemplateDto } from '../types';
import { TemplatePicker } from './TemplatePicker';

const own: ReportTemplateDto = {
    id: 7,
    name: 'Mensual estándar',
    blocks: [{ id: 'h', type: 'header', layout: { x: 0, y: 0, w: 12, h: 3 } }],
    calculated_metrics: [],
    is_default: true,
    locale: 'es',
    used_by: [{ id: 1, name: 'Informe Hotel', site: 'Hotel Sol' }, { id: 2, name: 'Informe Café', site: 'Café' }],
};

function setup(mode: 'start' | 'insert') {
    const handlers = { onGallery: vi.fn(), onDefault: vi.fn(), onOwn: vi.fn(), onAi: vi.fn(), onBlank: vi.fn(), onClose: vi.fn() };
    render(<TemplatePicker mode={mode} templates={[own]} {...handlers} />);

    return handlers;
}

describe('TemplatePicker', () => {
    it('offers every way to start, with the agency templates and who uses them', () => {
        const handlers = setup('start');

        expect(screen.getByText('¿Cómo quieres empezar?')).toBeTruthy();
        expect(screen.getByText('Usada por 2 clientes')).toBeTruthy();

        fireEvent.click(screen.getByText('Mensual estándar'));
        expect(handlers.onOwn).toHaveBeenCalledWith(own);

        fireEvent.click(screen.getByText('Créala con IA'));
        expect(handlers.onAi).toHaveBeenCalled();

        fireEvent.click(screen.getByText('Empezar en blanco'));
        expect(handlers.onBlank).toHaveBeenCalled();
    });

    it('hands back the chosen ready model', () => {
        const handlers = setup('insert');

        fireEvent.click(screen.getByText('Tienda WooCommerce'));
        expect(handlers.onGallery).toHaveBeenCalledWith(expect.objectContaining({ key: 'woocommerce' }));
        // No "blank" when adding to an existing report.
        expect(screen.queryByText('Empezar en blanco')).toBeNull();
    });
});
