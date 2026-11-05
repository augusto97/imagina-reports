import { Check, Copy, Link2, Mail, X } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import { useRequestReconnect } from '../api';
import type { DataSourceDto } from '../types';
import { Button, Field, Input, Modal } from './ui';

const TEXTAREA =
    'ir-w-full ir-rounded-lg ir-border ir-bg-card ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed ir-shadow-ir-xs ir-outline-none ir-transition placeholder:ir-text-muted-foreground/70 focus:ir-border-accent focus:ir-ring-2 focus:ir-ring-ring/40';

/**
 * «Pedir reconexión al cliente» (Etapa 5): emails the client a link — with the agency's brand —
 * that only lets them reconnect this one source, for a week. No passwords change hands.
 */
export function ReconnectRequest({
    source,
    label,
    siteId,
    siteName,
    clientEmail,
    onClose,
}: {
    source: DataSourceDto;
    label: string;
    siteId: number;
    siteName: string;
    clientEmail: string | null;
    onClose: () => void;
}): ReactElement {
    const request = useRequestReconnect(siteId);
    const [email, setEmail] = useState(source.reconnect_requested_to ?? clientEmail ?? '');
    const [message, setMessage] = useState('');
    const [copied, setCopied] = useState(false);
    const done = request.data;

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        request.mutate({ id: source.id, email: email.trim(), message: message.trim() });
    };

    const copy = (url: string): void => {
        void navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        });
    };

    return (
        <Modal onClose={onClose} className="ir-max-w-lg">
            <div className="ir-rounded-2xl ir-border ir-bg-card ir-p-6 ir-shadow-ir-lg">
                <div className="ir-flex ir-items-start ir-justify-between ir-gap-3">
                    <div className="ir-flex ir-items-start ir-gap-3">
                        <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-accent/10 ir-text-accent">
                            <Link2 className="ir-size-4" />
                        </span>
                        <div>
                            <h2 className="ir-text-lg ir-font-semibold ir-tracking-tight">Pedir reconexión al cliente</h2>
                            <p className="ir-mt-0.5 ir-text-[13px] ir-leading-relaxed ir-text-muted-foreground">
                                Le enviamos un correo con tu marca y un botón para volver a conectar {label} de {siteName}. Entra con su cuenta, sin compartir contraseñas. El enlace sirve solo para esa fuente y vence en 7 días.
                            </p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                        <X className="ir-size-4" />
                    </button>
                </div>

                {done !== undefined ? (
                    <div className="ir-mt-5 ir-flex ir-flex-col ir-gap-3">
                        <p className="ir-flex ir-items-center ir-gap-2 ir-rounded-lg ir-bg-success/10 ir-px-3 ir-py-2.5 ir-text-sm ir-text-success">
                            <Check className="ir-size-4 ir-shrink-0" />
                            Enviado a {done.sent_to}. Cuando reconecte, la fuente vuelve a sincronizarse sola.
                        </p>
                        <Field label="Si prefieres mandárselo tú (WhatsApp, chat…)">
                            <div className="ir-flex ir-gap-2">
                                <Input readOnly value={done.url} onFocus={(event) => event.target.select()} />
                                <Button type="button" variant="ghost" onClick={() => copy(done.url)}>
                                    {copied ? <Check className="ir-size-4" /> : <Copy className="ir-size-4" />}
                                    {copied ? 'Copiado' : 'Copiar'}
                                </Button>
                            </div>
                        </Field>
                        <div className="ir-flex ir-justify-end">
                            <Button onClick={onClose}>Listo</Button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="ir-mt-5 ir-flex ir-flex-col ir-gap-4">
                        <Field label="Correo del cliente">
                            <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="cliente@empresa.com" required />
                        </Field>
                        <Field label="Mensaje (opcional)" hint="Aparece en el correo, encima del botón.">
                            <textarea className={TEXTAREA} rows={3} maxLength={1000} value={message} onChange={(event) => setMessage(event.target.value)} placeholder={`Hola, se venció el permiso de ${label}. ¿Puedes volver a conectarlo? Es un minuto.`} />
                        </Field>
                        {request.isError && <p className="ir-text-xs ir-text-danger">{apiErrorMessage(request.error, 'No se pudo enviar el correo.')}</p>}
                        <div className="ir-flex ir-justify-end ir-gap-2">
                            <Button type="button" variant="ghost" onClick={onClose}>
                                Cancelar
                            </Button>
                            <Button type="submit" variant="accent" disabled={request.isPending || email.trim() === ''}>
                                <Mail className="ir-size-4" />
                                {request.isPending ? 'Enviando…' : 'Enviar al cliente'}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </Modal>
    );
}
