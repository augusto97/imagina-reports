import { Check, Copy, Link2, Mail, MessageCircle, ShieldCheck, X } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import { type ConnectInviteDto, useConnectInvites, useCreateConnectInvite, useRevokeConnectInvite } from '../api';
import { SOURCE_META } from '../lib/sources';
import type { Connector } from '../types';
import { Button, Modal, Segmented } from './ui';

const EXPIRY = [
    { value: '1', label: '24 horas' },
    { value: '7', label: '7 días' },
    { value: '30', label: '30 días' },
] as const;

type Expiry = (typeof EXPIRY)[number]['value'];

function dateLabel(iso: string): string {
    return new Date(iso).toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' });
}

function message(siteName: string, url: string): string {
    return `Hola, para preparar el reporte mensual de ${siteName} necesito acceso de solo lectura a tus estadísticas. Entra a este enlace y conecta tus cuentas con tu propio usuario (no tienes que crear cuenta ni compartir contraseñas): ${url}`;
}

function CopyLink({ url }: { url: string }): ReactElement {
    const [copied, setCopied] = useState(false);
    const copy = (): void => {
        void navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        });
    };

    return (
        <Button type="button" size="sm" variant="ghost" onClick={copy}>
            {copied ? <Check className="ir-size-3.5" /> : <Copy className="ir-size-3.5" />}
            {copied ? 'Copiado' : 'Copiar'}
        </Button>
    );
}

function InviteRow({ invite, siteName, labelFor, onRevoke, revoking }: {
    invite: ConnectInviteDto;
    siteName: string;
    labelFor: (type: string) => string;
    onRevoke: () => void;
    revoking: boolean;
}): ReactElement {
    const text = message(siteName, invite.url);

    return (
        <div className="ir-flex ir-flex-col ir-gap-2 ir-rounded-lg ir-border ir-bg-card ir-p-3">
            <div className="ir-flex ir-items-center ir-gap-2">
                <input readOnly value={invite.url} onFocus={(event) => event.currentTarget.select()} aria-label="Enlace de conexión"
                    className="ir-h-8 ir-min-w-0 ir-flex-1 ir-rounded-md ir-border ir-bg-muted/40 ir-px-2 ir-font-mono ir-text-xs" />
                <CopyLink url={invite.url} />
            </div>
            <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-2">
                <p className="ir-text-xs ir-text-muted-foreground">
                    {invite.types.map(labelFor).join(' · ')} — vence el {dateLabel(invite.expires_at)}
                    {invite.last_used_at !== null ? ` · abierto el ${dateLabel(invite.last_used_at)}` : ' · aún sin usar'}
                </p>
                <div className="ir-flex ir-items-center ir-gap-1">
                    <a className="ir-inline-flex ir-h-7 ir-items-center ir-gap-1 ir-rounded-md ir-px-2 ir-text-xs ir-font-medium ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                        href={`https://wa.me/?text=${encodeURIComponent(text)}`} target="_blank" rel="noreferrer">
                        <MessageCircle className="ir-size-3.5" />WhatsApp
                    </a>
                    <a className="ir-inline-flex ir-h-7 ir-items-center ir-gap-1 ir-rounded-md ir-px-2 ir-text-xs ir-font-medium ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                        href={`mailto:?subject=${encodeURIComponent(`Conecta tus cuentas para el reporte de ${siteName}`)}&body=${encodeURIComponent(text)}`}>
                        <Mail className="ir-size-3.5" />Email
                    </a>
                    <button type="button" onClick={onRevoke} disabled={revoking}
                        className="ir-inline-flex ir-h-7 ir-items-center ir-gap-1 ir-rounded-md ir-px-2 ir-text-xs ir-font-medium ir-text-danger hover:ir-bg-danger/10 disabled:ir-opacity-50">
                        Desactivar
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * «Pedir acceso al cliente»: builds a link the client opens without an account to connect
 * their own Google/Meta/WooCommerce accounts to this site. Lists the active links too.
 */
export function ClientConnectLink({ siteId, siteName, connectors, onClose }: {
    siteId: number;
    siteName: string;
    connectors: Connector[];
    onClose: () => void;
}): ReactElement {
    const oneClick = connectors.filter((connector) => connector.connect != null);
    const { data: invites = [] } = useConnectInvites(siteId);
    const create = useCreateConnectInvite(siteId);
    const revoke = useRevokeConnectInvite(siteId);
    const [types, setTypes] = useState<string[]>(() => oneClick.filter((c) => ['ga4', 'gsc'].includes(c.key)).map((c) => c.key));
    const [expiry, setExpiry] = useState<Expiry>('7');

    const labelFor = (type: string): string => oneClick.find((c) => c.key === type)?.label ?? SOURCE_META[type]?.label ?? type;
    const toggle = (key: string): void => setTypes((current) => (current.includes(key) ? current.filter((k) => k !== key) : [...current, key]));

    const confirmRevoke = (invite: ConnectInviteDto): void => {
        if (window.confirm('¿Desactivar este enlace? El cliente ya no podrá usarlo. Las fuentes que ya conectó siguen funcionando.')) {
            revoke.mutate(invite.id);
        }
    };

    return (
        <Modal onClose={onClose} className="ir-max-w-xl">
            <div className="ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-lg">
                <div className="ir-flex ir-items-start ir-justify-between ir-gap-3 ir-border-b ir-p-5">
                    <div className="ir-flex ir-gap-3">
                        <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-accent/10 ir-text-accent">
                            <Link2 className="ir-size-4" />
                        </span>
                        <div>
                            <h2 className="ir-text-base ir-font-semibold ir-tracking-tight">Pedir acceso al cliente</h2>
                            <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">
                                Envíale un enlace: lo abre sin cuenta, entra con su propio Google, Meta o tienda y las fuentes quedan conectadas a {siteName}.
                            </p>
                        </div>
                    </div>
                    <Button type="button" variant="quiet" size="icon" onClick={onClose} aria-label="Cerrar">
                        <X className="ir-size-4" />
                    </Button>
                </div>

                <div className="ir-flex ir-flex-col ir-gap-5 ir-p-5">
                    {oneClick.length === 0 ? (
                        <p className="ir-rounded-lg ir-bg-warning/10 ir-p-3 ir-text-sm ir-text-warning">
                            Aún no hay fuentes con conexión de un clic. Configura la app de Google o de Meta en Ajustes → Integraciones para poder enviar enlaces.
                        </p>
                    ) : (
                        <>
                            <div className="ir-flex ir-flex-col ir-gap-2">
                                <p className="ir-text-xs ir-font-medium ir-text-foreground/80">¿Qué tiene que conectar?</p>
                                <div className="ir-grid ir-gap-2 sm:ir-grid-cols-2">
                                    {oneClick.map((connector) => {
                                        const meta = SOURCE_META[connector.key];
                                        const Icon = meta?.icon;
                                        const on = types.includes(connector.key);

                                        return (
                                            <button key={connector.key} type="button" onClick={() => toggle(connector.key)} aria-pressed={on}
                                                className={`ir-flex ir-items-center ir-gap-2.5 ir-rounded-lg ir-border ir-p-2.5 ir-text-left ir-transition ${on ? 'ir-border-accent ir-bg-accent/5 ir-ring-1 ir-ring-accent' : 'hover:ir-bg-muted/60'}`}>
                                                <span className={`ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ${on ? 'ir-bg-accent ir-text-accent-foreground' : 'ir-bg-muted ir-text-muted-foreground'}`}>
                                                    {on ? <Check className="ir-size-3.5" /> : Icon !== undefined ? <Icon className="ir-size-3.5" /> : null}
                                                </span>
                                                <span className="ir-min-w-0">
                                                    <span className="ir-block ir-truncate ir-text-sm ir-font-medium">{connector.label}</span>
                                                    {meta?.blurb !== undefined && <span className="ir-block ir-truncate ir-text-xs ir-text-muted-foreground">{meta.blurb}</span>}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="ir-flex ir-flex-wrap ir-items-end ir-justify-between ir-gap-3">
                                <div className="ir-flex ir-flex-col ir-gap-1.5">
                                    <p className="ir-text-xs ir-font-medium ir-text-foreground/80">El enlace vence en</p>
                                    <Segmented value={expiry} onChange={setExpiry} items={EXPIRY.map((item) => ({ value: item.value, label: item.label }))} />
                                </div>
                                <Button type="button" onClick={() => create.mutate({ types, expires_days: Number(expiry) })} disabled={types.length === 0 || create.isPending}>
                                    <Link2 className="ir-size-4" />
                                    {create.isPending ? 'Creando…' : 'Crear enlace'}
                                </Button>
                            </div>
                            {create.isError && <p className="ir-text-sm ir-text-danger">{apiErrorMessage(create.error, 'No se pudo crear el enlace.')}</p>}
                        </>
                    )}

                    {invites.length > 0 && (
                        <div className="ir-flex ir-flex-col ir-gap-2">
                            <p className="ir-text-xs ir-font-medium ir-text-foreground/80">Enlaces activos</p>
                            {invites.map((invite) => (
                                <InviteRow key={invite.id} invite={invite} siteName={siteName} labelFor={labelFor} onRevoke={() => confirmRevoke(invite)} revoking={revoke.isPending} />
                            ))}
                        </div>
                    )}

                    <div className="ir-flex ir-gap-2.5 ir-rounded-lg ir-bg-muted/50 ir-p-3 ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                        <ShieldCheck className="ir-mt-0.5 ir-size-4 ir-shrink-0 ir-text-success" />
                        <p>
                            El enlace solo sirve para este sitio y para las fuentes que elijas; nunca muestra tus datos ni tus otros clientes. El acceso es de solo lectura, vence solo y puedes desactivarlo cuando quieras. Cuando el cliente conecte algo, lo verás aquí en sus fuentes y en el registro de actividad.
                        </p>
                    </div>
                </div>
            </div>
        </Modal>
    );
}
