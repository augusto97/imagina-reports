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
});
