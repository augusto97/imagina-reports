import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Block } from './types';
import { BlockRenderer } from './BlockRenderer';

describe('BlockRenderer', () => {
    it('renders a narrative block from its resolved data', () => {
        const block: Block = { id: 'summary', type: 'narrative', props: { title: 'Resumen' } };

        render(<BlockRenderer block={block} data="Este mes todo funcionó bien." />);

        expect(screen.getByText('Resumen')).toBeInTheDocument();
        expect(screen.getByText('Este mes todo funcionó bien.')).toBeInTheDocument();
    });

    it('falls back to props.text when there is no resolved data', () => {
        const block: Block = { id: 'n', type: 'narrative', props: { text: 'Texto estático' } };

        render(<BlockRenderer block={block} data={null} />);

        expect(screen.getByText('Texto estático')).toBeInTheDocument();
    });

    it('renders a goal block in budget mode with an over-budget warning', () => {
        const block: Block = {
            id: 'b',
            type: 'goal',
            props: { label: 'Presupuesto', target: 1000 },
            style: { format: 'currency', goal_direction: 'under' },
        };

        // Spent 1200 against a 1000 budget → over budget.
        render(<BlockRenderer block={block} data={1200} />);

        expect(screen.getByText('Presupuesto')).toBeInTheDocument();
        expect(screen.getByText(/te pasaste/)).toBeInTheDocument();
        expect(screen.getByText(/120% del presupuesto/)).toBeInTheDocument();
    });

    it('renders the site goals with how far each one is', () => {
        const block: Block = { id: 'g', type: 'goals', props: { title: 'Metas del mes' } };

        render(
            <BlockRenderer
                block={block}
                data={[
                    { label: 'Visitas del mes', value: 4820, target: 5000, direction: 'at_least', format: 'number', progress: 96.4, met: false },
                    { label: 'Disponibilidad', value: 99.98, target: 99.9, direction: 'at_least', format: 'percent', progress: 100.1, met: true },
                    { label: 'Tiempo de carga', value: 2.4, target: 2, direction: 'at_most', format: 'number', progress: 120, met: false },
                ]}
            />,
        );

        expect(screen.getByText('1 de 3 cumplidas')).toBeInTheDocument();
        expect(screen.getByText(/96 % de la meta/)).toBeInTheDocument();
        expect(screen.getByText('Meta cumplida')).toBeInTheDocument();
        // 99,98 % never reads as a rounded-up 100 %.
        expect(screen.getByText('99,98%')).toBeInTheDocument();
        expect(screen.getByText(/Se pasó del límite/)).toBeInTheDocument();
    });

    it('renders nothing for a goals block without goals', () => {
        const { container } = render(<BlockRenderer block={{ id: 'g', type: 'goals' }} data={[]} />);

        expect(container.textContent).toBe('');
    });
});
