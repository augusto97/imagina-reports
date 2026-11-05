import { ArrowUp, Loader2, MessageCircleQuestion, Send, Sparkles, X } from 'lucide-react';
import { type FormEvent, type ReactElement, useEffect, useRef, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';
import { cn } from '@shared/lib/utils';

import { type PortalMessage, rememberedIdentity, rememberIdentity, useAskReport, useConversation, useSendQuestion } from './conversation';

const SUGGESTIONS = ['¿Cómo le fue a mi sitio este mes?', '¿Qué trabajos hicieron este mes?', '¿Por qué cambiaron las visitas?'];

const FIELD =
    'ir-w-full ir-rounded-xl ir-border ir-bg-card ir-px-3 ir-py-2.5 ir-text-sm ir-outline-none ir-transition placeholder:ir-text-muted-foreground/70 focus:ir-border-primary focus:ir-ring-2 focus:ir-ring-primary/20';

function time(iso: string): string {
    return new Date(iso).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).replace('.', '');
}

function Mine({ text, note }: { text: string; note?: string }): ReactElement {
    return (
        <div className="ir-flex ir-flex-col ir-items-end">
            <div className="ir-max-w-[85%] ir-whitespace-pre-line ir-rounded-2xl ir-rounded-tr-md ir-bg-primary ir-px-3.5 ir-py-2.5 ir-text-sm ir-leading-relaxed ir-text-primary-foreground">{text}</div>
            {note !== undefined && <p className="ir-mt-1 ir-px-1 ir-text-[11px] ir-text-muted-foreground">{note}</p>}
        </div>
    );
}

function Theirs({ who, text, icon, note }: { who: string; text: string; icon?: ReactElement; note?: string }): ReactElement {
    return (
        <div className="ir-flex ir-flex-col ir-items-start">
            <p className="ir-mb-1 ir-flex ir-items-center ir-gap-1 ir-px-1 ir-text-[11px] ir-font-medium ir-text-muted-foreground">
                {icon}
                {who}
            </p>
            <div className="ir-max-w-[90%] ir-whitespace-pre-line ir-rounded-2xl ir-rounded-tl-md ir-border ir-bg-card ir-px-3.5 ir-py-2.5 ir-text-sm ir-leading-relaxed">{text}</div>
            {note !== undefined && <p className="ir-mt-1 ir-px-1 ir-text-[11px] ir-text-muted-foreground">{note}</p>}
        </div>
    );
}

/**
 * «Pregunta sobre tu informe» (Etapa 4): the client asks in their own words. With AI on, the
 * answer comes at once from the report's own figures; either way they can send the question to
 * the agency, who answers here (and by email when they leave one). No account needed.
 */
export function AskPanel({ token, password, agency }: { token: string; password?: string; agency: string }): ReactElement {
    const [open, setOpen] = useState(() => new URLSearchParams(window.location.search).get('preguntas') === '1');
    const { data } = useConversation(token, password, open);
    const ask = useAskReport(token, password);
    const send = useSendQuestion(token, password);
    const aiOn = data?.ai_available === true;

    // 'ai' asks the report; 'agency' writes to the agency (needs a name).
    const [mode, setMode] = useState<'ai' | 'agency'>('ai');
    const [text, setText] = useState('');
    const [identity, setIdentity] = useState(rememberedIdentity);
    const [honeypot, setHoneypot] = useState('');
    const [sent, setSent] = useState(false);
    const end = useRef<HTMLDivElement>(null);
    const effectiveMode = aiOn ? mode : 'agency';
    const messages: PortalMessage[] = data?.messages ?? [];
    const pending = ask.isPending || send.isPending;

    useEffect(() => {
        if (open) {
            end.current?.scrollIntoView({ block: 'end' });
        }
    }, [open, messages.length, pending]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') setOpen(false);
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    const askAi = (question: string): void => {
        if (question.trim().length < 3) return;
        setSent(false);
        ask.mutate({ question: question.trim(), name: identity.name !== '' ? identity.name : undefined }, { onSuccess: () => setText('') });
    };

    const toAgency = (body: string): void => {
        if (body.trim().length < 2 || identity.name.trim() === '') return;
        rememberIdentity({ name: identity.name.trim(), email: identity.email.trim() });
        send.mutate(
            { name: identity.name.trim(), email: identity.email.trim(), body: body.trim(), website: honeypot },
            {
                onSuccess: () => {
                    setText('');
                    setSent(true);
                    if (aiOn) setMode('ai');
                },
            },
        );
    };

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (effectiveMode === 'ai') askAi(text);
        else toAgency(text);
    };

    const lastAi = [...messages].reverse().find((message) => message.kind === 'ai');
    const error = ask.error ?? send.error;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'ir-fixed ir-bottom-4 ir-right-4 ir-z-30 ir-inline-flex ir-h-12 ir-items-center ir-gap-2 ir-rounded-full ir-bg-primary ir-pl-4 ir-pr-5 ir-text-sm ir-font-semibold ir-text-primary-foreground ir-shadow-lg ir-transition hover:ir-brightness-110 print:ir-hidden sm:ir-bottom-6 sm:ir-right-6',
                    open && 'ir-hidden',
                )}
            >
                <MessageCircleQuestion className="ir-size-5" />
                <span className="ir-hidden sm:ir-inline">¿Tienes una pregunta?</span>
                <span className="sm:ir-hidden">Preguntar</span>
            </button>

            {open && (
                <div className="ir-fixed ir-inset-0 ir-z-40 ir-flex ir-justify-end print:ir-hidden" role="dialog" aria-modal="true" aria-label="Pregunta sobre tu informe">
                    <button type="button" aria-label="Cerrar" onClick={() => setOpen(false)} className="ir-absolute ir-inset-0 ir-cursor-default ir-bg-black/30 ir-backdrop-blur-[1px]" />
                    <section className="ir-relative ir-flex ir-h-full ir-w-full ir-flex-col ir-bg-muted/95 ir-shadow-2xl sm:ir-max-w-md sm:ir-border-l">
                        <header className="ir-flex ir-items-start ir-gap-3 ir-border-b ir-bg-card ir-px-5 ir-py-4">
                            <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-xl ir-bg-primary/10 ir-text-primary">
                                <MessageCircleQuestion className="ir-size-5" />
                            </span>
                            <div className="ir-min-w-0 ir-flex-1">
                                <h2 className="ir-text-base ir-font-semibold ir-tracking-tight">Pregunta sobre tu informe</h2>
                                <p className="ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                                    {aiOn ? `Te respondemos al instante con los datos del informe, o te contesta ${agency}.` : `Te contesta ${agency} aquí mismo.`}
                                </p>
                            </div>
                            <button type="button" onClick={() => setOpen(false)} aria-label="Cerrar" className="ir-rounded-lg ir-p-1.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                                <X className="ir-size-4" />
                            </button>
                        </header>

                        <div className="ir-flex-1 ir-overflow-y-auto ir-px-5 ir-py-5">
                            {messages.length === 0 && !pending && (
                                <div className="ir-flex ir-flex-col ir-gap-3">
                                    <p className="ir-text-sm ir-text-muted-foreground">
                                        {aiOn ? 'Pregunta lo que quieras sobre tu sitio en este periodo. Por ejemplo:' : `Escribe tu duda y ${agency} te responderá.`}
                                    </p>
                                    {aiOn &&
                                        SUGGESTIONS.map((suggestion) => (
                                            <button
                                                key={suggestion}
                                                type="button"
                                                onClick={() => askAi(suggestion)}
                                                className="ir-rounded-xl ir-border ir-bg-card ir-px-3.5 ir-py-2.5 ir-text-left ir-text-sm ir-transition hover:ir-border-primary/40 hover:ir-text-primary"
                                            >
                                                {suggestion}
                                            </button>
                                        ))}
                                </div>
                            )}

                            <div className="ir-flex ir-flex-col ir-gap-4">
                                {messages.map((message) =>
                                    message.kind === 'ai' ? (
                                        <div key={message.id} className="ir-flex ir-flex-col ir-gap-3">
                                            <Mine text={message.body} />
                                            {message.answer !== null && (
                                                <Theirs
                                                    who="Respuesta automática"
                                                    icon={<Sparkles className="ir-size-3" />}
                                                    text={message.answer}
                                                    note={message.id === lastAi?.id ? undefined : time(message.created_at)}
                                                />
                                            )}
                                            {message.id === lastAi?.id && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setMode('agency');
                                                        setText(message.body);
                                                    }}
                                                    className="ir-self-start ir-px-1 ir-text-xs ir-font-medium ir-text-primary hover:ir-underline"
                                                >
                                                    ¿No te sirvió? Pregúntale a {agency} →
                                                </button>
                                            )}
                                        </div>
                                    ) : message.kind === 'client' ? (
                                        <Mine key={message.id} text={message.body} note={`Enviado a ${agency} · ${time(message.created_at)}`} />
                                    ) : (
                                        <Theirs key={message.id} who={`${message.author ?? agency} · ${agency}`} text={message.body} note={time(message.created_at)} />
                                    ),
                                )}
                                {pending && (
                                    <p className="ir-flex ir-items-center ir-gap-2 ir-text-xs ir-text-muted-foreground">
                                        <Loader2 className="ir-size-3.5 ir-animate-spin" />
                                        {ask.isPending ? 'Buscando la respuesta en tu informe…' : 'Enviando…'}
                                    </p>
                                )}
                                {sent && !pending && (
                                    <p className="ir-rounded-xl ir-bg-emerald-500/10 ir-px-3.5 ir-py-2.5 ir-text-xs ir-leading-relaxed ir-text-emerald-800">
                                        Listo, se lo enviamos a {agency}. Te responderá aquí{identity.email !== '' ? ` y por correo a ${identity.email}` : ''}.
                                    </p>
                                )}
                            </div>
                            <div ref={end} />
                        </div>

                        <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-2 ir-border-t ir-bg-card ir-px-4 ir-py-3">
                            {aiOn && (
                                <div className="ir-flex ir-gap-1 ir-rounded-lg ir-bg-muted ir-p-0.5 ir-text-xs ir-font-medium">
                                    {(
                                        [
                                            ['ai', 'Respuesta al instante'],
                                            ['agency', `Escribir a ${agency}`],
                                        ] as const
                                    ).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => setMode(value)}
                                            className={cn('ir-flex-1 ir-truncate ir-rounded-md ir-px-2 ir-py-1.5 ir-transition', mode === value ? 'ir-bg-card ir-text-foreground ir-shadow-sm' : 'ir-text-muted-foreground hover:ir-text-foreground')}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {effectiveMode === 'agency' && (
                                <div className="ir-grid ir-grid-cols-2 ir-gap-2">
                                    <input className={FIELD} placeholder="Tu nombre" value={identity.name} maxLength={80} onChange={(event) => setIdentity({ ...identity, name: event.target.value })} required aria-label="Tu nombre" />
                                    <input
                                        className={FIELD}
                                        type="email"
                                        placeholder="Tu correo (opcional)"
                                        value={identity.email}
                                        maxLength={190}
                                        onChange={(event) => setIdentity({ ...identity, email: event.target.value })}
                                        aria-label="Tu correo, para avisarte cuando respondan"
                                    />
                                    {/* Honeypot: invisible to people, irresistible to bots. */}
                                    <input type="text" tabIndex={-1} autoComplete="off" value={honeypot} onChange={(event) => setHoneypot(event.target.value)} className="ir-hidden" aria-hidden name="website" />
                                </div>
                            )}

                            <div className="ir-flex ir-items-end ir-gap-2">
                                <textarea
                                    className={cn(FIELD, 'ir-max-h-36 ir-min-h-[2.75rem] ir-resize-none')}
                                    rows={effectiveMode === 'agency' ? 3 : 1}
                                    value={text}
                                    maxLength={effectiveMode === 'ai' ? 500 : 2000}
                                    onChange={(event) => setText(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' && !event.shiftKey && effectiveMode === 'ai') {
                                            event.preventDefault();
                                            askAi(text);
                                        }
                                    }}
                                    placeholder={effectiveMode === 'ai' ? 'Escribe tu pregunta…' : `Tu mensaje para ${agency}…`}
                                    aria-label="Tu pregunta"
                                />
                                <button
                                    type="submit"
                                    disabled={pending || text.trim().length < 2 || (effectiveMode === 'agency' && identity.name.trim() === '')}
                                    className="ir-flex ir-size-11 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-xl ir-bg-primary ir-text-primary-foreground ir-transition hover:ir-brightness-110 disabled:ir-opacity-40"
                                    aria-label={effectiveMode === 'ai' ? 'Preguntar' : 'Enviar'}
                                >
                                    {effectiveMode === 'ai' ? <ArrowUp className="ir-size-5" /> : <Send className="ir-size-4" />}
                                </button>
                            </div>
                            {error !== null && <p className="ir-text-xs ir-text-danger">{apiErrorMessage(error, 'No pudimos enviarlo. Inténtalo de nuevo.')}</p>}
                            {effectiveMode === 'ai' && <p className="ir-text-[11px] ir-text-muted-foreground">Las respuestas automáticas usan solo los datos de este informe.</p>}
                        </form>
                    </section>
                </div>
            )}
        </>
    );
}
