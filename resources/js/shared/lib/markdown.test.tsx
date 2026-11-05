import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SimpleMarkdown } from './markdown';

describe('SimpleMarkdown', () => {
    it('shows bold, lists and paragraphs instead of the raw asterisks', () => {
        const { container } = render(
            <SimpleMarkdown text={'En Google, el resultado es mixto:\n\n- **Clics:** 9, frente a 8.\n- **Posición:** 23,9\n\nLa portada fue la *más* vista.'} />,
        );

        expect(container.textContent).not.toContain('**');
        expect(container.querySelectorAll('li')).toHaveLength(2);
        expect(container.querySelector('li strong')?.textContent).toBe('Clics:');
        expect(container.querySelector('em')?.textContent).toBe('más');
        expect(container.querySelectorAll('p')).toHaveLength(2);
    });

    it('never turns text into markup', () => {
        const { container } = render(<SimpleMarkdown text={'<img src=x onerror=alert(1)> **hola**'} />);

        expect(container.querySelector('img')).toBeNull();
        expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('handles numbered lists and headings', () => {
        const { container } = render(<SimpleMarkdown text={'## Resumen\n1. Uno\n2. Dos'} />);

        expect(container.querySelectorAll('ol li')).toHaveLength(2);
        expect(container.textContent).not.toContain('##');
    });
});
