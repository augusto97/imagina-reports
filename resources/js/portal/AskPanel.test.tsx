import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AskPanel } from './AskPanel';
import type { PortalConversation } from './conversation';

const state: { conversation: PortalConversation } = { conversation: { messages: [], ai_available: true, agency: 'Agencia Norte' } };
const ask = vi.fn();
const send = vi.fn();

vi.mock('./conversation', () => ({
    useConversation: () => ({ data: state.conversation }),
    useAskReport: () => ({ mutate: ask, isPending: false, error: null }),
    useSendQuestion: () => ({ mutate: send, isPending: false, error: null }),
    rememberedIdentity: () => ({ name: '', email: '' }),
    rememberIdentity: () => undefined,
}));

describe('AskPanel', () => {
    beforeEach(() => {
        // jsdom has no layout, so no scrollIntoView.
        Element.prototype.scrollIntoView = vi.fn();
        ask.mockReset();
        send.mockReset();
        state.conversation = { messages: [], ai_available: true, agency: 'Agencia Norte' };
    });

    it('asks the report with one click on a suggestion', () => {
        render(<AskPanel token="t" agency="Agencia Norte" />);
        fireEvent.click(screen.getByRole('button', { name: /Tienes una pregunta/ }));
        fireEvent.click(screen.getByRole('button', { name: '¿Cómo le fue a mi sitio este mes?' }));

        expect(ask).toHaveBeenCalledWith({ question: '¿Cómo le fue a mi sitio este mes?', name: undefined }, expect.anything());
    });

    it('without AI it writes to the agency and asks who is writing', () => {
        state.conversation = { messages: [], ai_available: false, agency: 'Agencia Norte' };
        render(<AskPanel token="t" agency="Agencia Norte" />);
        fireEvent.click(screen.getByRole('button', { name: /Tienes una pregunta/ }));

        expect(screen.queryByText('Respuesta al instante')).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Tu nombre'), { target: { value: 'Laura' } });
        fireEvent.change(screen.getByLabelText('Tu pregunta'), { target: { value: '¿Podemos hablar?' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enviar' }));

        expect(send).toHaveBeenCalledWith({ name: 'Laura', email: '', body: '¿Podemos hablar?', website: '' }, expect.anything());
    });
});
