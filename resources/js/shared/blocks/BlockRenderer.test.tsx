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
});
