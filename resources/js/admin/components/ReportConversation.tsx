import { Bot, Check, MessageCircleQuestion, Send } from 'lucide-react';
import { type FormEvent, type ReactElement, useEffect, useRef, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';
import { SimpleMarkdown } from '@shared/lib/markdown';
import { cn } from '@shared/lib/utils';

import { useMarkQuestionsHandled, useReplyToClient, useReportMessages } from '../api';
import type { ReportMessageDto } from '../types';
import { Button } from './ui';

const inputClass =
    'ir-w-full ir-rounded-lg ir-border ir-bg-card ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed ir-shadow-ir-xs ir-outline-none ir-transition placeholder:ir-text-muted-foreground/70 focus:ir-border-accent focus:ir-ring-2 focus:ir-ring-ring/40';

function when(iso: string): string {
    const date = new Date(iso);

    return date.toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).replace('.', '');
}

function Bubble({ message }: { message: ReportMessageDto }): ReactElement {
    if (message.kind === 'ai') {
        return (
            <li className="ir-rounded-xl ir-border ir-border-dashed ir-bg-muted/30 ir-px-3.5 ir-py-2.5 ir-text-[13px]">
                <p className="ir-flex ir-items-center ir-gap-1.5 ir-text-xs ir-text-muted-foreground">
                    <Bot className="ir-size-3.5" />
                    {message.author ?? 'El cliente'} preguntó a la IA · {when(message.created_at)}
                </p>
                <p className="ir-mt-1 ir-font-medium">«{message.body}»</p>
                {message.answer !== null && <SimpleMarkdown text={message.answer} className="ir-mt-1 ir-text-muted-foreground" />}
            </li>
        );
    }

    const fromClient = message.kind === 'client';

    return (
        <li className={cn('ir-flex ir-flex-col', fromClient ? 'ir-items-start' : 'ir-items-end')}>
            <p className="ir-mb-1 ir-px-1 ir-text-[11px] ir-text-muted-foreground">
                <span className="ir-font-medium ir-text-foreground/80">{message.author ?? (fromClient ? 'Cliente' : 'Tu equipo')}</span>
                {fromClient && message.author_email != null && message.author_email !== '' && <span> · {message.author_email}</span>} · {when(message.created_at)}
            </p>
            <div className={cn('ir-max-w-[85%] ir-whitespace-pre-line ir-rounded-2xl ir-px-3.5 ir-py-2.5 ir-text-sm ir-leading-relaxed', fromClient ? 'ir-rounded-tl-md ir-bg-muted' : 'ir-rounded-tr-md ir-bg-accent ir-text-accent-foreground')}>
                {message.body}
            </div>
        </li>
    );
}

/**
 * The conversation with the client on one report (Etapa 4): their questions from the portal,
 * what they asked the AI, and the agency's answers — which reach the client by email when they
 * left one, and in the portal either way.
 */
export function ReportConversation({ reportId }: { reportId: number }): ReactElement {
    const { data: messages = [], isLoading } = useReportMessages(reportId);
    const reply = useReplyToClient(reportId);
    const handled = useMarkQuestionsHandled(reportId);
    const [body, setBody] = useState('');
    const end = useRef<HTMLDivElement>(null);

    const pending = messages.filter((message) => message.kind === 'client' && (message.handled_at ?? null) === null);
    const lastClient = [...messages].reverse().find((message) => message.kind === 'client');
    const email = lastClient?.author_email ?? null;

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (body.trim() === '') {
            return;
        }
        reply.mutate(body.trim(), { onSuccess: () => setBody('') });
    };

    if (!isLoading && messages.length === 0) {
        return (
            <div className="ir-flex ir-flex-col ir-items-center ir-gap-2 ir-rounded-xl ir-border ir-border-dashed ir-px-6 ir-py-8 ir-text-center">
                <MessageCircleQuestion className="ir-size-6 ir-text-muted-foreground" />
                <p className="ir-text-sm ir-font-medium">Aún no hay preguntas</p>
                <p className="ir-max-w-sm ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                    Tu cliente puede escribirte desde su informe con «Pregunta sobre tu informe». Te avisamos por correo y las respondes aquí.
                </p>
            </div>
        );
    }

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <ul className="ir-flex ir-flex-col ir-gap-3">
                {messages.map((message) => (
                    <Bubble key={message.id} message={message} />
                ))}
            </ul>
            <div ref={end} />

            {lastClient !== undefined && (
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-2 ir-border-t ir-pt-4">
                    <textarea
                        className={inputClass}
                        rows={3}
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                        placeholder={`Responde a ${lastClient.author ?? 'tu cliente'}…`}
                    />
                    <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-2">
                        <p className="ir-text-xs ir-text-muted-foreground">
                            {email !== null && email !== '' ? `Le llegará por correo a ${email} y la verá en su informe.` : 'No dejó correo: verá tu respuesta cuando vuelva a abrir su informe.'}
                        </p>
                        <div className="ir-ml-auto ir-flex ir-items-center ir-gap-1.5">
                            {pending.length > 0 && (
                                <Button type="button" variant="ghost" size="sm" onClick={() => handled.mutate()} disabled={handled.isPending} title="Ya lo resolviste por otra vía o no requiere respuesta">
                                    <Check className="ir-size-3.5" />
                                    Marcar como resuelta
                                </Button>
                            )}
                            <Button type="submit" variant="accent" size="sm" disabled={reply.isPending || body.trim() === ''}>
                                <Send className="ir-size-3.5" />
                                {reply.isPending ? 'Enviando…' : 'Responder'}
                            </Button>
                        </div>
                    </div>
                    {reply.isError && <p className="ir-text-xs ir-text-danger">{apiErrorMessage(reply.error, 'No se pudo enviar la respuesta.')}</p>}
                </form>
            )}
        </div>
    );
}
