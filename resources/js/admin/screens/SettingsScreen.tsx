import { Bot, Building2, Check, Copy, CreditCard, Plug, Plus, Send, ShieldCheck, Trash2, User, Webhook } from 'lucide-react';
import { type ReactElement, type ReactNode, useEffect, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import {
    type AgencyUpdate,
    useAgency,
    useApiTokens,
    useAuditLogs,
    useAuthUser,
    useBilling,
    useCancelSubscription,
    useChangePassword,
    useConfirmTwoFactor,
    useCreateApiToken,
    useDeleteAgency,
    useDisableTwoFactor,
    useRevokeApiToken,
    useStartTwoFactor,
    useSubscribe,
    useRefreshAiModels,
    useTestAi,
    useTestWebhooks,
    useAgencyDomain,
    useAgencySender,
    useUpdateAgency,
    useUpdateProfile,
    useUploadLogo,
} from '../api';
import { AiModelFields } from '../components/AiModelFields';
import { Badge, Button, Card, Field, Input, PageHeader, Select, StatusDot } from '../components/ui';
import type { AgencySettings } from '../types';
import { SiteAgentPluginCard } from './SystemScreen';

const SUB_STATUS: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
    active: { label: 'Activa', tone: 'success' },
    pending: { label: 'Pendiente de pago', tone: 'warning' },
    past_due: { label: 'Pago vencido', tone: 'warning' },
    suspended: { label: 'Suspendida', tone: 'danger' },
    cancelled: { label: 'Cancelada', tone: 'neutral' },
};

const LOCALES: { value: string; label: string }[] = [
    { value: 'es', label: 'Español' },
    { value: 'en', label: 'English' },
    { value: 'pt_BR', label: 'Português (BR)' },
];

const WEBHOOK_EVENTS = ['report.generated', 'report.sent', 'anomaly.detected', 'upsell.detected', 'report.question', 'source.failed'];

/**
 * The agency's SAVED identity fields. Every card here saves on its own, and the update
 * endpoint requires `name` on each request — so each one re-sends the stored values it
 * isn't editing instead of clobbering them with a stale local copy.
 */
function agencyBase(agency: AgencySettings): AgencyUpdate {
    return { name: agency.name, brand_color: agency.brand_color, default_locale: agency.default_locale };
}

/** The server explains why a checkout failed (e.g. MercadoPago's reason); surface it verbatim. */
function subscribeError(error: unknown): string {
    const message = (error as { response?: { data?: { message?: unknown } } }).response?.data?.message;

    return typeof message === 'string' && message !== '' ? message : 'No se pudo iniciar el pago. Inténtalo de nuevo.';
}

/* ------------------------------- Mi cuenta -------------------------------- */

/** Edit the signed-in user's own name + login email. */
function ProfileCard(): ReactElement {
    const { data: user } = useAuthUser();
    const update = useUpdateProfile();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [currentPassword, setCurrentPassword] = useState('');
    const [seeded, setSeeded] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (user !== undefined && !seeded) {
            setSeeded(true);
            setName(user.name);
            setEmail(user.email);
        }
    }, [user, seeded]);

    // Changing the login email requires the current password (account-recovery factor).
    const emailChanged = user !== undefined && email.trim() !== user.email;

    const submit = (): void => {
        setError('');
        update.mutate(
            { name: name.trim(), email: email.trim(), ...(emailChanged ? { current_password: currentPassword } : {}) },
            {
                onSuccess: () => setCurrentPassword(''),
                onError: () => setError(emailChanged
                    ? 'No se pudo guardar. Revisa que la contraseña actual sea correcta y que el email no esté en uso.'
                    : 'No se pudo guardar. ¿El email ya está en uso o es inválido?'),
            },
        );
    };

    return (
        <Card title="Tus datos" description="Tu nombre y el email con el que entras a Imagina Reports.">
            <div className="ir-flex ir-flex-col ir-gap-4">
                <Field label="Tu nombre">
                    <Input value={name} onChange={(event) => setName(event.target.value)} />
                </Field>
                <Field
                    label="Email de acceso"
                    hint="Es el correo con el que inicias sesión. No tiene nada que ver con el email de tu cuenta de MercadoPago (ese está en «Plan y pagos»)."
                >
                    <Input type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} />
                </Field>
                {user?.pending_email != null && user.pending_email !== '' && (
                    <p className="ir-rounded-md ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-xs ir-text-warning">
                        Cambio pendiente: enviamos un enlace a <strong>{user.pending_email}</strong>. El correo de acceso no cambiará hasta que lo confirmes desde ese buzón.
                    </p>
                )}
                {emailChanged && (
                    <Field label="Contraseña actual" hint="Necesaria para cambiar el email de acceso.">
                        <Input type="password" autoComplete="current-password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} />
                    </Field>
                )}
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={submit} disabled={update.isPending || name.trim() === '' || email.trim() === '' || (emailChanged && currentPassword === '')}>
                        {update.isPending ? 'Guardando…' : 'Guardar mis datos'}
                    </Button>
                    {update.isSuccess && (
                        <span className="ir-text-xs ir-text-success">
                            {emailChanged ? 'Te enviamos un enlace al nuevo correo para confirmarlo.' : 'Datos actualizados.'}
                        </span>
                    )}
                    {error !== '' && <span className="ir-text-xs ir-text-danger">{error}</span>}
                </div>
            </div>
        </Card>
    );
}

function PasswordCard(): ReactElement {
    const change = useChangePassword();
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState('');

    const submit = (): void => {
        setError('');
        if (next !== confirm) {
            setError('La nueva contraseña y su confirmación no coinciden.');

            return;
        }
        change.mutate(
            { current_password: current, password: next, password_confirmation: confirm },
            {
                onSuccess: () => {
                    setCurrent('');
                    setNext('');
                    setConfirm('');
                },
                onError: () => setError('No se pudo cambiar. Revisa que la contraseña actual sea correcta y la nueva tenga al menos 8 caracteres.'),
            },
        );
    };

    return (
        <Card title="Contraseña" description="Cámbiala cuando quieras. Necesitas saber la actual.">
            <div className="ir-flex ir-flex-col ir-gap-4">
                <Field label="Contraseña actual">
                    <Input type="password" autoComplete="current-password" value={current} onChange={(event) => setCurrent(event.target.value)} />
                </Field>
                <Field label="Nueva contraseña (mín. 8)">
                    <Input type="password" autoComplete="new-password" value={next} onChange={(event) => setNext(event.target.value)} />
                </Field>
                <Field label="Repite la nueva contraseña">
                    <Input type="password" autoComplete="new-password" value={confirm} onChange={(event) => setConfirm(event.target.value)} />
                </Field>
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={submit} disabled={change.isPending || current === '' || next === ''}>
                        {change.isPending ? 'Cambiando…' : 'Cambiar contraseña'}
                    </Button>
                    {change.isSuccess && <span className="ir-text-xs ir-text-success">Contraseña actualizada.</span>}
                    {error !== '' && <span className="ir-text-xs ir-text-danger">{error}</span>}
                </div>
            </div>
        </Card>
    );
}

function TwoFactorCard(): ReactElement {
    const { data: user } = useAuthUser();
    const start = useStartTwoFactor();
    const confirm = useConfirmTwoFactor();
    const disable = useDisableTwoFactor();
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [codes, setCodes] = useState<string[]>([]);

    const enabled = user?.two_factor_enabled === true;

    return (
        <Card
            title="Verificación en dos pasos (2FA)"
            description="Añade un código de un solo uso al iniciar sesión. Funciona con Google Authenticator, 1Password, Authy…"
            actions={enabled ? <Badge tone="success">Activada</Badge> : <Badge tone="warning">Desactivada</Badge>}
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                {!enabled && start.data == null && (
                    <Button className="ir-self-start" onClick={() => start.mutate()} disabled={start.isPending}>
                        {start.isPending ? 'Preparando…' : 'Activar 2FA'}
                    </Button>
                )}

                {!enabled && start.data != null && codes.length === 0 && (
                    <div className="ir-flex ir-flex-col ir-gap-3 ir-rounded-md ir-border ir-bg-muted/30 ir-p-3">
                        <p className="ir-text-xs ir-text-muted-foreground">
                            1) Añade esta clave en tu app de autenticación (entrada manual) y 2) escribe el código de 6 dígitos que te muestre.
                        </p>
                        <div>
                            <p className="ir-mb-1 ir-text-[11px] ir-text-muted-foreground">Clave secreta:</p>
                            <pre className="ir-overflow-x-auto ir-rounded ir-bg-foreground/5 ir-p-2 ir-text-[13px] ir-tracking-widest">{start.data.secret}</pre>
                        </div>
                        <details>
                            <summary className="ir-cursor-pointer ir-text-[11px] ir-text-muted-foreground">Ver enlace otpauth:// (para apps que lo aceptan)</summary>
                            <pre className="ir-mt-1 ir-overflow-x-auto ir-rounded ir-bg-foreground/5 ir-p-2 ir-text-[10px]">{start.data.otpauth_uri}</pre>
                        </details>
                        <Field label="Código de 6 dígitos">
                            <Input inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="123456" />
                        </Field>
                        {confirm.isError && <p className="ir-text-xs ir-text-danger">El código no es válido. Comprueba la hora del teléfono.</p>}
                        <Button
                            className="ir-self-start"
                            onClick={() => confirm.mutate(code, { onSuccess: (data) => { setCodes(data.recovery_codes); setCode(''); } })}
                            disabled={confirm.isPending || code === ''}
                        >
                            Confirmar y activar
                        </Button>
                    </div>
                )}

                {codes.length > 0 && (
                    <div className="ir-flex ir-flex-col ir-gap-2 ir-rounded-md ir-border ir-border-success/40 ir-bg-success/5 ir-p-3">
                        <p className="ir-text-xs ir-font-medium ir-text-success">
                            ✅ 2FA activada. Guarda estos códigos de recuperación ahora — son tu forma de entrar si pierdes el teléfono y no se volverán a mostrar.
                        </p>
                        <pre className="ir-overflow-x-auto ir-rounded ir-bg-foreground/5 ir-p-2 ir-text-[12px]">{codes.join('\n')}</pre>
                        <Button variant="ghost" size="sm" className="ir-self-start" onClick={() => setCodes([])}>Ya los guardé</Button>
                    </div>
                )}

                {enabled && (
                    <div>
                        <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">Para desactivarla, confirma tu contraseña.</p>
                        <div className="ir-flex ir-gap-2">
                            <Input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Contraseña actual" />
                            <Button variant="ghost" onClick={() => disable.mutate(password, { onSuccess: () => setPassword('') })} disabled={disable.isPending || password === ''}>
                                Desactivar
                            </Button>
                        </div>
                        {disable.isError && <p className="ir-mt-2 ir-text-xs ir-text-danger">No se pudo desactivar. ¿La contraseña es correcta?</p>}
                    </div>
                )}
            </div>
        </Card>
    );
}

/* ------------------------------- Mi agencia -------------------------------- */

/** The agency's public identity: name, language, colour and logo. Saves on its own. */
function BrandingCard({ agency }: { agency: AgencySettings }): ReactElement {
    const update = useUpdateAgency();
    const uploadLogo = useUploadLogo();
    const [name, setName] = useState(agency.name);
    const [color, setColor] = useState(agency.brand_color ?? '#6d28d9');
    const [locale, setLocale] = useState(agency.default_locale);

    const whiteLabel = agency.limits.features.white_label === true;

    const save = (): void => {
        update.mutate({
            name,
            // Without the white-label feature the colour is read-only: re-send the stored
            // value so saving the name/locale still works (the server 403s a change).
            brand_color: whiteLabel ? color : agency.brand_color,
            default_locale: locale,
        });
    };

    return (
        <Card title="Identidad y marca" description="Cómo se ve tu agencia en los reportes que reciben tus clientes.">
            <div className="ir-flex ir-flex-col ir-gap-4">
                {!whiteLabel && (
                    <p className="ir-rounded-md ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-xs ir-text-warning">
                        Tu plan no incluye marca blanca: el color y el logo no se pueden personalizar. Mejora tu plan para desbloquearlo.
                    </p>
                )}
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                    <Field label="Nombre de la agencia" hint="Aparece en la cabecera de cada reporte.">
                        <Input value={name} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="Idioma por defecto de los reportes">
                        <Select value={locale} onChange={(event) => setLocale(event.target.value)}>
                            {LOCALES.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                </div>
                <Field label="Color de marca">
                    <div className="ir-flex ir-items-center ir-gap-3">
                        <input
                            type="color"
                            value={color}
                            disabled={!whiteLabel}
                            onChange={(event) => setColor(event.target.value)}
                            className="ir-h-9 ir-w-12 ir-rounded ir-border disabled:ir-opacity-50"
                        />
                        <Input value={color} disabled={!whiteLabel} onChange={(event) => setColor(event.target.value)} className="ir-w-32" />
                    </div>
                </Field>
                <Field label="Logo" hint="PNG, JPG o WebP. Se sube al momento, no hace falta guardar.">
                    <div className="ir-flex ir-items-center ir-gap-4">
                        {agency.logo_url !== null && (
                            <img src={agency.logo_url} alt="Logo" className="ir-h-10 ir-rounded ir-border ir-bg-white ir-p-1" />
                        )}
                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            disabled={!whiteLabel}
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                if (file !== undefined) {
                                    uploadLogo.mutate(file);
                                }
                            }}
                            className="ir-text-sm disabled:ir-opacity-50"
                        />
                        {uploadLogo.isPending && <span className="ir-text-xs ir-text-muted-foreground">Subiendo…</span>}
                    </div>
                </Field>
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={save} disabled={update.isPending || name === ''}>
                        {update.isPending ? 'Guardando…' : 'Guardar marca'}
                    </Button>
                    {update.isSuccess && <span className="ir-text-xs ir-text-success">Marca guardada.</span>}
                    {update.isError && <span className="ir-text-xs ir-text-danger">No se pudo guardar.</span>}
                </div>
            </div>
        </Card>
    );
}

/** How clients reach the agency: shown in the client portal and used as reply-to of the report email. */
function ContactCard({ agency }: { agency: AgencySettings }): ReactElement {
    const update = useUpdateAgency();
    const [email, setEmail] = useState(agency.contact_email);
    const [phone, setPhone] = useState(agency.contact_phone);
    const [website, setWebsite] = useState(agency.website);

    const save = (): void => {
        update.mutate({ ...agencyBase(agency), contact_email: email.trim(), contact_phone: phone.trim(), website: website.trim() });
    };

    return (
        <Card title="Contacto para tus clientes" description="Aparece en el portal del reporte («Escríbenos») y las respuestas al correo del reporte llegan a este email.">
            <div className="ir-flex ir-flex-col ir-gap-4">
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                    <Field label="Email de contacto" hint="Si lo dejas vacío, las respuestas llegan al email del propietario.">
                        <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="hola@tuagencia.com" />
                    </Field>
                    <Field label="WhatsApp" hint="Con código de país, p. ej. +57 300 123 4567.">
                        <Input value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="+57 300 123 4567" />
                    </Field>
                </div>
                <Field label="Sitio web de la agencia">
                    <Input type="url" value={website} onChange={(event) => setWebsite(event.target.value)} placeholder="https://tuagencia.com" />
                </Field>
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={save} disabled={update.isPending}>
                        {update.isPending ? 'Guardando…' : 'Guardar contacto'}
                    </Button>
                    {update.isSuccess && <span className="ir-text-xs ir-text-success">Contacto guardado.</span>}
                    {update.isError && <span className="ir-text-xs ir-text-danger">{apiErrorMessage(update.error, 'No se pudo guardar.')}</span>}
                </div>
            </div>
        </Card>
    );
}

/** Shown instead of a white-label card when the plan doesn't include it. */
function PlanLocked({ title, description }: { title: string; description: string }): ReactElement {
    return (
        <Card title={title} description={description}>
            <p className="ir-rounded-lg ir-bg-muted/60 ir-px-3 ir-py-2.5 ir-text-[13px] ir-text-muted-foreground">
                Incluido en los planes con «Dominio propio». Mejora tu plan en «Plan y pagos» para activarlo.
            </p>
        </Card>
    );
}

function CopyValue({ value }: { value: string }): ReactElement {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={() => void navigator.clipboard?.writeText(value).then(() => { setCopied(true); window.setTimeout(() => setCopied(false), 1500); })}
            className="ir-inline-flex ir-items-center ir-gap-1 ir-rounded-md ir-border ir-bg-card ir-px-2 ir-py-1 ir-font-mono ir-text-xs hover:ir-bg-muted"
            title="Copiar"
        >
            {value}
            {copied ? <Check className="ir-size-3 ir-text-success" /> : <Copy className="ir-size-3 ir-text-muted-foreground" />}
        </button>
    );
}

/** reportes.tuagencia.com: the client pages (portal, report, dashboard) on the agency's own domain. */
function DomainCard({ agency }: { agency: AgencySettings }): ReactElement {
    const { save, check } = useAgencyDomain();
    const [domain, setDomain] = useState(agency.domain ?? '');
    const allowed = agency.limits.features.custom_domain === true;
    const title = 'Dominio propio';
    const description = 'Tus clientes abren sus informes en tu dominio (p. ej. reportes.tuagencia.com) en lugar del de la plataforma.';

    if (!allowed) {
        return <PlanLocked title={title} description={description} />;
    }

    const status = agency.domain_status;
    const label = agency.domain ?? '';
    const sub = label.split('.')[0] ?? label;
    const steps: { label: string; done: boolean; current: boolean; text: ReactNode }[] = [
        {
            label: 'Apunta el dominio',
            done: status === 'pending_ssl' || status === 'active',
            current: status === 'pending_dns',
            text: (
                <div className="ir-flex ir-flex-col ir-gap-2">
                    <span>En el DNS de tu dominio crea este registro:</span>
                    <div className="ir-grid ir-grid-cols-[auto_1fr] ir-items-center ir-gap-x-3 ir-gap-y-1.5 ir-rounded-lg ir-bg-muted/50 ir-p-3 ir-text-xs">
                        <span className="ir-text-muted-foreground">Tipo</span>
                        <span className="ir-font-mono">CNAME</span>
                        <span className="ir-text-muted-foreground">Nombre</span>
                        <span className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                            <CopyValue value={label} />
                            <span className="ir-text-muted-foreground">(en muchos paneles basta con «{sub}»)</span>
                        </span>
                        <span className="ir-text-muted-foreground">Destino</span>
                        <span><CopyValue value={agency.domain_target} /></span>
                    </div>
                    <span className="ir-text-xs ir-text-muted-foreground">Si usas Cloudflare, déjalo en «Solo DNS» (nube gris).</span>
                </div>
            ),
        },
        {
            label: 'Certificado de seguridad (HTTPS)',
            done: status === 'active',
            current: status === 'pending_ssl',
            text: 'Lo activamos nosotros en el servidor en cuanto el DNS apunta bien. No tienes que hacer nada.',
        },
        {
            label: 'Listo',
            done: status === 'active',
            current: false,
            text: status === 'active' ? `Los enlaces y correos a tus clientes ya usan ${agency.public_base_url}.` : 'Los enlaces que envías pasarán a usar tu dominio automáticamente.',
        },
    ];

    return (
        <Card
            title={title}
            description={description}
            actions={
                agency.domain !== null ? (
                    <span className={`ir-inline-flex ir-items-center ir-gap-1.5 ir-text-xs ir-font-medium ${status === 'active' ? 'ir-text-success' : 'ir-text-warning'}`}>
                        <StatusDot tone={status === 'active' ? 'success' : 'warning'} pulse={status !== 'active'} />
                        {status === 'active' ? 'Activo' : 'Configurando'}
                    </span>
                ) : undefined
            }
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                <div className="ir-flex ir-flex-wrap ir-items-end ir-gap-2">
                    <div className="ir-min-w-[16rem] ir-flex-1">
                        <Field label="Dominio" hint="Usa un subdominio, como reportes.tuagencia.com: tu web principal no se toca.">
                            <Input value={domain} onChange={(event) => setDomain(event.target.value)} placeholder="reportes.tuagencia.com" />
                        </Field>
                    </div>
                    <Button onClick={() => save.mutate(domain.trim())} disabled={save.isPending || domain.trim() === '' || domain.trim() === agency.domain}>
                        {save.isPending ? 'Comprobando…' : agency.domain === null ? 'Conectar dominio' : 'Cambiar'}
                    </Button>
                </div>
                {save.isError && <p className="ir-text-xs ir-text-danger">{apiErrorMessage(save.error, 'No se pudo guardar el dominio.')}</p>}

                {agency.domain !== null && (
                    <>
                        <ol className="ir-flex ir-flex-col ir-gap-3">
                            {steps.map((step, index) => (
                                <li key={step.label} className="ir-flex ir-gap-3">
                                    <span
                                        className={`ir-flex ir-size-6 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-text-xs ir-font-semibold ${
                                            step.done ? 'ir-bg-success ir-text-white' : step.current ? 'ir-bg-accent ir-text-accent-foreground' : 'ir-bg-muted ir-text-muted-foreground'
                                        }`}
                                    >
                                        {step.done ? <Check className="ir-size-3.5" /> : index + 1}
                                    </span>
                                    <div className="ir-min-w-0 ir-flex-1 ir-pt-0.5">
                                        <p className={`ir-text-sm ir-font-medium ${step.done || step.current ? '' : 'ir-text-muted-foreground'}`}>{step.label}</p>
                                        {(step.current || (index === 0 && !step.done) || (index === 2 && step.done)) && <div className="ir-mt-1 ir-text-[13px] ir-text-muted-foreground">{step.text}</div>}
                                    </div>
                                </li>
                            ))}
                        </ol>
                        {agency.domain_error !== null && status !== 'active' && (
                            <p className="ir-rounded-lg ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-xs ir-leading-relaxed ir-text-warning">{agency.domain_error}</p>
                        )}
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3 ir-border-t ir-pt-4">
                            <Button variant="ghost" size="sm" onClick={() => check.mutate()} disabled={check.isPending}>
                                {check.isPending ? 'Comprobando…' : 'Comprobar ahora'}
                            </Button>
                            {agency.domain_checked_at !== null && (
                                <span className="ir-text-xs ir-text-muted-foreground">
                                    Última comprobación: {new Date(agency.domain_checked_at).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' })}. Se revisa sola cada 15 minutos.
                                </span>
                            )}
                            <button
                                type="button"
                                className="ir-ml-auto ir-text-xs ir-text-muted-foreground hover:ir-text-danger"
                                onClick={() => {
                                    if (window.confirm('¿Quitar el dominio? Los enlaces nuevos volverán a usar la dirección de la plataforma.')) {
                                        setDomain('');
                                        save.mutate('');
                                    }
                                }}
                            >
                                Quitar dominio
                            </button>
                        </div>
                    </>
                )}
            </div>
        </Card>
    );
}

/** The agency's own SMTP, so report emails come from its address. */
function SenderCard({ agency }: { agency: AgencySettings }): ReactElement {
    const { save, remove, test } = useAgencySender();
    const current = agency.sender;
    const [fromName, setFromName] = useState(current?.from_name ?? agency.name);
    const [fromAddress, setFromAddress] = useState(current?.from_address ?? '');
    const [host, setHost] = useState(current?.host ?? '');
    const [port, setPort] = useState(String(current?.port ?? '587'));
    const [username, setUsername] = useState(current?.username ?? '');
    const [password, setPassword] = useState('');
    const [scheme, setScheme] = useState(current?.scheme ?? '');
    const [testTo, setTestTo] = useState('');
    const allowed = agency.limits.features.custom_domain === true;
    const title = 'Correo de envío';
    const description = 'Los informes salen desde tu propia dirección (p. ej. informes@tuagencia.com) en vez de la de la plataforma.';

    if (!allowed) {
        return <PlanLocked title={title} description={description} />;
    }

    const submit = (): void => {
        save.mutate({ from_name: fromName.trim(), from_address: fromAddress.trim(), host: host.trim(), port: Number(port) || 587, username: username.trim(), scheme, ...(password !== '' ? { password } : {}) }, { onSuccess: () => setPassword('') });
    };

    return (
        <Card
            title={title}
            description={description}
            actions={
                <span className={`ir-inline-flex ir-items-center ir-gap-1.5 ir-text-xs ir-font-medium ${current !== null ? 'ir-text-success' : 'ir-text-muted-foreground'}`}>
                    <StatusDot tone={current !== null ? 'success' : 'neutral'} />
                    {current !== null ? 'Tu servidor' : 'Desde la plataforma'}
                </span>
            }
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                {current === null && (
                    <p className="ir-rounded-lg ir-bg-muted/60 ir-px-3 ir-py-2.5 ir-text-[13px] ir-text-muted-foreground">
                        Ahora los correos salen desde la plataforma con el nombre de tu agencia y las respuestas te llegan a ti. Para que salgan desde tu dominio, usa el SMTP de tu
                        correo (Google Workspace, Microsoft 365, Zoho, tu hosting…).
                    </p>
                )}
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                    <Field label="Nombre del remitente">
                        <Input value={fromName} onChange={(event) => setFromName(event.target.value)} placeholder={agency.name} />
                    </Field>
                    <Field label="Dirección de envío">
                        <Input type="email" value={fromAddress} onChange={(event) => setFromAddress(event.target.value)} placeholder="informes@tuagencia.com" />
                    </Field>
                    <Field label="Servidor SMTP">
                        <Input value={host} onChange={(event) => setHost(event.target.value)} placeholder="smtp.gmail.com" />
                    </Field>
                    <div className="ir-grid ir-grid-cols-2 ir-gap-3">
                        <Field label="Puerto">
                            <Input value={port} onChange={(event) => setPort(event.target.value)} placeholder="587" />
                        </Field>
                        <Field label="Cifrado">
                            <Select value={scheme} onChange={(event) => setScheme(event.target.value)}>
                                <option value="">Automático</option>
                                <option value="smtp">STARTTLS (587)</option>
                                <option value="smtps">SSL/TLS (465)</option>
                            </Select>
                        </Field>
                    </div>
                    <Field label="Usuario">
                        <Input value={username} onChange={(event) => setUsername(event.target.value)} placeholder="informes@tuagencia.com" autoComplete="off" />
                    </Field>
                    <Field label="Contraseña" hint={current?.password_set === true ? 'Déjala vacía para conservar la guardada.' : 'Con Google o Microsoft, usa una contraseña de aplicación.'}>
                        <Input type="password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder={current?.password_set === true ? '••••••••' : ''} autoComplete="new-password" />
                    </Field>
                </div>
                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                    <Button onClick={submit} disabled={save.isPending || fromAddress.trim() === '' || host.trim() === ''}>
                        {save.isPending ? 'Guardando…' : 'Guardar servidor'}
                    </Button>
                    {save.isSuccess && <span className="ir-text-xs ir-text-success">Guardado.</span>}
                    {save.isError && <span className="ir-text-xs ir-text-danger">{apiErrorMessage(save.error, 'No se pudo guardar.')}</span>}
                    {current !== null && (
                        <button
                            type="button"
                            className="ir-ml-auto ir-text-xs ir-text-muted-foreground hover:ir-text-danger"
                            onClick={() => {
                                if (window.confirm('¿Volver a enviar desde la plataforma?')) remove.mutate();
                            }}
                        >
                            Dejar de usar mi servidor
                        </button>
                    )}
                </div>
                {current !== null && (
                    <div className="ir-flex ir-flex-col ir-gap-2 ir-border-t ir-pt-4">
                        <p className="ir-text-xs ir-text-muted-foreground">Envía un correo de prueba para confirmar que funciona.</p>
                        <div className="ir-flex ir-gap-2">
                            <Input type="email" value={testTo} onChange={(event) => setTestTo(event.target.value)} placeholder="tu@correo.com" />
                            <Button variant="ghost" onClick={() => test.mutate(testTo)} disabled={test.isPending || testTo === ''}>
                                {test.isPending ? 'Enviando…' : 'Enviar prueba'}
                            </Button>
                        </div>
                        {test.data?.sent === true && <p className="ir-text-xs ir-text-success">Enviado. Revisa la bandeja (y el spam).</p>}
                        {test.data?.sent === false && (
                            <p className="ir-rounded-lg ir-bg-danger/10 ir-p-2 ir-text-xs ir-text-danger">
                                No se pudo enviar. Respuesta del servidor: <span className="ir-font-mono">{test.data.error}</span>
                            </p>
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}

/* ------------------------------ Plan y pagos ------------------------------- */

/** The agency's plan + live usage against its limits (SaaS Fase 1). */
function PlanUsageCard({ agency }: { agency: AgencySettings }): ReactElement {
    const rows: { label: string; used: number; limit: number | null }[] = [
        { label: 'Sitios', used: agency.usage.sites, limit: agency.limits.max_sites },
        { label: 'Fuentes de datos', used: agency.usage.data_sources, limit: agency.limits.max_data_sources },
        { label: 'Clientes', used: agency.usage.clients, limit: agency.limits.max_clients },
        { label: 'Reportes este mes', used: agency.usage.reports_this_month, limit: agency.limits.max_reports_per_month },
    ];

    return (
        <Card title="Tu consumo" description={agency.plan !== null ? `Estás en el plan ${agency.plan.name}.` : 'Sin plan asignado (sin límites).'}>
            <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                {rows.map((row) => {
                    const pct = row.limit === null || row.limit === 0 ? 0 : Math.min(100, (row.used / row.limit) * 100);
                    const over = row.limit !== null && row.used >= row.limit;

                    return (
                        <div key={row.label}>
                            <div className="ir-flex ir-justify-between ir-text-xs ir-text-muted-foreground">
                                <span>{row.label}</span>
                                <span className={over ? 'ir-font-medium ir-text-danger' : ''}>
                                    {row.used}/{row.limit ?? '∞'}
                                </span>
                            </div>
                            <div className="ir-mt-1 ir-h-1.5 ir-overflow-hidden ir-rounded ir-bg-muted">
                                <div className={`ir-h-full ir-rounded ${over ? 'ir-bg-danger' : 'ir-bg-primary'}`} style={{ width: `${row.limit === null ? 6 : pct}%` }} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

/** Agency self-service billing: current subscription + choose a plan and pay with MP/PayPal. */
function BillingCard(): ReactElement | null {
    const { data: billing } = useBilling();
    const subscribe = useSubscribe();
    const cancel = useCancelSubscription();
    // null = untouched (fall back to the suggested billing email once it loads).
    const [payerEmail, setPayerEmail] = useState<string | null>(null);

    if (billing === undefined) {
        return null;
    }

    const hasMercadoPago = billing.providers.some((provider) => provider.key === 'mercadopago');
    const effectiveEmail = payerEmail !== null ? payerEmail : (billing.billing_email ?? '');

    const start = (planId: number, provider: string): void => {
        // MercadoPago must be paid with the account whose email matches the subscription.
        const email = provider === 'mercadopago' && effectiveEmail !== '' ? effectiveEmail : undefined;
        subscribe.mutate({ planId, provider, payerEmail: email }, { onSuccess: (data) => { window.location.href = data.approval_url; } });
    };

    const sub = billing.subscription;
    const subMeta = sub !== null ? (SUB_STATUS[sub.status] ?? { label: sub.status, tone: 'neutral' as const }) : null;
    const isActive = sub?.status === 'active';
    const noProviders = billing.providers.length === 0;

    return (
        <>
            {hasMercadoPago && !noProviders && (
                <Card
                    title="Email de cobro (MercadoPago)"
                    description="Solo se usa para cobrarte la suscripción. Cambiarlo aquí NO cambia el email con el que inicias sesión."
                >
                    <Field
                        label="Email de tu cuenta de MercadoPago"
                        hint="Debe ser el email de la cuenta con la que pagarás en MercadoPago; si no coincide, el pago se rechaza. Tu email de acceso se cambia en «Mi cuenta»."
                    >
                        <Input
                            type="email"
                            value={effectiveEmail}
                            onChange={(event) => setPayerEmail(event.target.value)}
                            placeholder="tucuenta@ejemplo.com"
                        />
                    </Field>
                </Card>
            )}

            <Card
                title="Tu suscripción"
                description={
                    billing.plan !== null && isActive
                        ? `Estás suscrito al plan ${billing.plan.name}.`
                        : 'Elige un plan y suscríbete para activar tu cuenta.'
                }
                actions={sub !== null && subMeta !== null ? <Badge tone={subMeta.tone}>{subMeta.label}</Badge> : undefined}
            >
                <div className="ir-flex ir-flex-col ir-gap-4">
                    {sub !== null && (
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                            <p className="ir-text-xs ir-text-muted-foreground">
                                Método de pago: {sub.provider}
                                {sub.current_period_end !== null && ` · próximo cobro el ${new Date(sub.current_period_end).toLocaleDateString()}`}
                            </p>
                            {sub.status !== 'cancelled' && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={cancel.isPending}
                                    onClick={() => {
                                        if (window.confirm('Se cancelará la suscripción y no se harán más cobros. Conservas el acceso hasta el final del periodo que ya pagaste. ¿Continuar?')) {
                                            cancel.mutate();
                                        }
                                    }}
                                >
                                    {cancel.isPending ? 'Cancelando…' : 'Cancelar suscripción'}
                                </Button>
                            )}
                        </div>
                    )}

                    {cancel.isSuccess && (
                        <p className="ir-rounded-md ir-bg-muted/50 ir-px-3 ir-py-2 ir-text-xs ir-text-muted-foreground">
                            {cancel.data.message}
                            {cancel.data.access_until !== null && ` Conservas el acceso hasta el ${new Date(cancel.data.access_until).toLocaleDateString()}.`}
                        </p>
                    )}
                    {cancel.isError && <p className="ir-text-xs ir-text-danger">No se pudo cancelar. Inténtalo de nuevo o contacta con soporte.</p>}

                    {sub?.status === 'past_due' && (
                        <p className="ir-rounded-md ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-sm ir-text-warning">
                            No pudimos cobrar tu última cuota. Revisa el medio de pago en {sub.provider}: si no se resuelve, el acceso se
                            suspenderá cuando termine el periodo de gracia.
                        </p>
                    )}

                    {billing.status === 'suspended' && (
                        <p className="ir-rounded-md ir-bg-danger/5 ir-px-3 ir-py-2 ir-text-sm ir-text-danger">
                            Tu cuenta está suspendida. Suscríbete a un plan para reactivarla.
                        </p>
                    )}

                    {noProviders && (
                        <p className="ir-rounded-md ir-bg-muted/50 ir-px-3 ir-py-2 ir-text-xs ir-text-muted-foreground">
                            Aún no hay métodos de pago habilitados. Contacta con soporte.
                        </p>
                    )}

                    {billing.plans.length === 0 ? (
                        <p className="ir-text-sm ir-text-muted-foreground">No hay planes disponibles todavía.</p>
                    ) : (
                        <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-3">
                            {billing.plans.map((plan) => {
                                const current = plan.id === billing.current_plan_id && isActive;

                                return (
                                    <div key={plan.id} className={`ir-flex ir-flex-col ir-gap-2 ir-rounded-lg ir-border ir-p-3 ${current ? 'ir-border-accent ir-bg-accent/5' : 'ir-bg-card'}`}>
                                        <div className="ir-flex ir-items-center ir-justify-between">
                                            <p className="ir-text-sm ir-font-semibold">{plan.name}</p>
                                            {current && <Badge tone="accent">Actual</Badge>}
                                        </div>
                                        <p className="ir-text-lg ir-font-semibold">
                                            {plan.monthly_price != null ? `${plan.monthly_price} ${plan.currency}` : '—'}
                                            <span className="ir-text-xs ir-font-normal ir-text-muted-foreground">/mes</span>
                                        </p>
                                        <ul className="ir-flex ir-flex-col ir-gap-0.5 ir-text-xs ir-text-muted-foreground">
                                            <li>{plan.max_sites ?? '∞'} sitios · {plan.max_clients ?? '∞'} clientes</li>
                                            <li>{plan.max_users ?? '∞'} usuarios{plan.features?.ai_builder ? ' · IA incluida' : ''}</li>
                                        </ul>
                                        {!noProviders && plan.monthly_price != null && (
                                            <div className="ir-mt-1 ir-flex ir-flex-col ir-gap-1.5">
                                                {billing.providers.map((provider) => (
                                                    <Button key={provider.key} variant={current ? 'ghost' : 'outline'} size="sm" onClick={() => start(plan.id, provider.key)} disabled={subscribe.isPending}>
                                                        <CreditCard className="ir-size-3.5" />
                                                        {current ? `Renovar con ${provider.label}` : `Suscribirme · ${provider.label}`}
                                                    </Button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {subscribe.isError && (
                        <p className="ir-rounded-md ir-bg-danger/5 ir-px-3 ir-py-2 ir-text-xs ir-text-danger">{subscribeError(subscribe.error)}</p>
                    )}
                </div>
            </Card>
        </>
    );
}

/* ------------------------------ Integraciones ------------------------------ */

/** The agency's own Anthropic key for the AI builder. Saves on its own. */
/** Which emails the team gets (Etapa 5). Each switch saves on its own. */
function NotifyCard({ agency }: { agency: AgencySettings }): ReactElement {
    const update = useUpdateAgency();
    const items: { key: keyof AgencySettings['notify']; title: string; text: string }[] = [
        {
            key: 'source_failures',
            title: 'Cuando una conexión deja de funcionar',
            text: 'Un correo a los dueños y administradores, una sola vez por caída, con el botón para pedirle al cliente que la reconecte.',
        },
        {
            key: 'client_questions',
            title: 'Cuando un cliente pregunta desde su informe',
            text: 'Llega a tu correo de contacto (o a los dueños y administradores si no lo tienes). Responder a ese correo le contesta directo al cliente.',
        },
    ];

    return (
        <Card title="Avisos por correo" description="Slack y los webhooks reciben estos eventos siempre; aquí decides qué llega también por correo.">
            <div className="ir-flex ir-flex-col ir-divide-y">
                {items.map((item) => (
                    <label key={item.key} className="ir-flex ir-items-start ir-gap-3 ir-py-3 first:ir-pt-0 last:ir-pb-0">
                        <input
                            type="checkbox"
                            className="ir-mt-1"
                            checked={agency.notify[item.key]}
                            disabled={update.isPending}
                            onChange={(event) => update.mutate({ ...agencyBase(agency), notify: { [item.key]: event.target.checked } })}
                        />
                        <span>
                            <span className="ir-block ir-text-sm ir-font-medium">{item.title}</span>
                            <span className="ir-block ir-text-xs ir-leading-relaxed ir-text-muted-foreground">{item.text}</span>
                        </span>
                    </label>
                ))}
            </div>
            {update.isError && <p className="ir-mt-3 ir-text-xs ir-text-danger">No se pudo guardar.</p>}
        </Card>
    );
}

function AiCard({ agency }: { agency: AgencySettings }): ReactElement {
    const update = useUpdateAgency();
    const test = useTestAi();
    const [apiKey, setApiKey] = useState('');
    const own = agency.ai_key_set;
    const refresh = useRefreshAiModels();

    const status = own ? (
        <Badge tone="success">Tu clave</Badge>
    ) : agency.ai_platform_available ? (
        <Badge tone="success">La de la plataforma</Badge>
    ) : (
        <Badge tone="warning">Sin configurar</Badge>
    );

    return (
        <Card
            title="Inteligencia artificial (Claude)"
            description="Escribe el resumen de cada informe, arma plantillas con «Generar con IA», responde las preguntas de tus clientes y redacta tus propuestas."
            actions={status}
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                <p className="ir-rounded-lg ir-bg-muted/60 ir-px-3 ir-py-2 ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                    {own
                        ? 'Usas tu propia clave: el consumo se cobra en tu cuenta de Anthropic y eliges el modelo y el nivel de esfuerzo aquí abajo.'
                        : agency.ai_platform_available
                          ? `Ahora usas la IA incluida en la plataforma (${agency.ai_platform_model}). Si pegas tu propia clave, el consumo va a tu cuenta de Anthropic y puedes elegir el modelo y el nivel de esfuerzo.`
                          : 'Pega tu clave de Anthropic para activar la IA. Mientras tanto, el resumen de cada informe se escribe solo a partir de las cifras.'}
                </p>
                <Field label="Tu clave de Anthropic (opcional)" hint="Créala en console.anthropic.com → API Keys. Se guarda cifrada y nunca se vuelve a mostrar.">
                    <Input
                        type="password"
                        autoComplete="off"
                        placeholder={own ? '•••••••• (deja en blanco para conservar)' : 'sk-ant-…'}
                        value={apiKey}
                        onChange={(event) => setApiKey(event.target.value)}
                    />
                </Field>
                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                    <Button
                        onClick={() => update.mutate({ ...agencyBase(agency), anthropic_key: apiKey.trim() }, { onSuccess: () => setApiKey('') })}
                        disabled={update.isPending || apiKey.trim() === ''}
                    >
                        {update.isPending ? 'Guardando…' : 'Guardar clave'}
                    </Button>
                    {own && (
                        <Button
                            variant="ghost"
                            disabled={update.isPending}
                            onClick={() => {
                                if (window.confirm('¿Quitar tu clave? Volverás a usar la IA de la plataforma (si la hay).')) {
                                    update.mutate({ ...agencyBase(agency), anthropic_key: '' });
                                }
                            }}
                        >
                            Quitar mi clave
                        </Button>
                    )}
                    {update.isSuccess && <span className="ir-text-xs ir-text-success">Guardado.</span>}
                    {update.isError && <span className="ir-text-xs ir-text-danger">No se pudo guardar.</span>}
                </div>

                <AiModelFields
                    choices={agency.ai_choices}
                    model={own ? (agency.ai_model ?? '') : ''}
                    onModel={(value) => update.mutate({ ...agencyBase(agency), ai_model: value === '' ? null : value })}
                    modelNow={agency.ai_model_now}
                    inheritModel={agency.ai_platform_model}
                    efforts={agency.ai_efforts}
                    effort={own ? (agency.ai_effort ?? '') : ''}
                    onEffort={(value) => update.mutate({ ...agencyBase(agency), ai_effort: value === '' ? null : value })}
                    effortNow={agency.ai_effort_now}
                    inheritEffort={agency.ai_platform_effort}
                    modelKnown={agency.ai_model_known}
                    disabled={!own || update.isPending}
                    disabledHint={
                        own
                            ? undefined
                            : `Usas el de la plataforma (${agency.ai_platform_model}). Para elegir modelo y esfuerzo necesitas tu propia clave: con la de la plataforma, ella decide.`
                    }
                    error={agency.ai_models_error}
                    onRefresh={() => refresh.mutate()}
                    refreshing={refresh.isPending}
                />

                {(own || agency.ai_platform_available) && (
                    <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                        <Button variant="ghost" onClick={() => test.mutate()} disabled={test.isPending}>
                            {test.isPending ? 'Probando…' : 'Probar la IA'}
                        </Button>
                        {test.data !== undefined && (
                            <span className={`ir-text-xs ${test.data.ok ? 'ir-text-success' : 'ir-text-danger'}`}>{test.data.message}</span>
                        )}
                    </div>
                )}

                <label className="ir-flex ir-items-start ir-gap-3 ir-border-t ir-pt-4">
                    <input
                        type="checkbox"
                        className="ir-mt-1"
                        checked={agency.client_ai}
                        disabled={update.isPending}
                        onChange={(event) => update.mutate({ ...agencyBase(agency), client_ai: event.target.checked })}
                    />
                    <span>
                        <span className="ir-block ir-text-sm ir-font-medium">Respuestas automáticas a tus clientes</span>
                        <span className="ir-block ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                            En su informe, tus clientes pueden preguntar («¿por qué bajaron las visitas?») y la IA responde al instante solo con los datos del informe. Lo que preguntan lo ves en Reportes → Preguntas. Máximo 20 preguntas por informe al día.
                        </span>
                    </span>
                </label>
            </div>
        </Card>
    );
}

/**
 * Outbound webhooks / integrations (CLAUDE.md §8). Self-contained so saving here never
 * clobbers unsaved edits elsewhere: it re-sends the agency's SAVED branding alongside
 * the webhook fields (the update endpoint expects them).
 */
function WebhooksCard({ agency }: { agency: AgencySettings }): ReactElement {
    const update = useUpdateAgency();
    const test = useTestWebhooks();
    const [urls, setUrls] = useState<string[]>(agency.webhook_urls.length > 0 ? agency.webhook_urls : ['']);
    const [secret, setSecret] = useState('');
    const [slackUrl, setSlackUrl] = useState(agency.slack_webhook_url);

    const setUrl = (index: number, value: string): void => setUrls((prev) => prev.map((url, i) => (i === index ? value : url)));
    const addUrl = (): void => setUrls((prev) => [...prev, '']);
    const removeUrl = (index: number): void => setUrls((prev) => prev.filter((_, i) => i !== index));

    const save = (): void => {
        const payload: AgencyUpdate = {
            ...agencyBase(agency),
            webhook_urls: urls.map((url) => url.trim()).filter((url) => url !== ''),
            slack_webhook_url: slackUrl.trim(),
        };
        if (secret !== '') {
            payload.webhook_secret = secret;
        }
        update.mutate(payload, { onSuccess: () => setSecret('') });
    };

    return (
        <Card
            title="Webhooks y Slack"
            description="Envía eventos a Zapier, Make, un CRM o Slack en cuanto ocurren. Cada endpoint recibe un POST con el evento y sus datos."
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                <div className="ir-flex ir-flex-wrap ir-gap-1.5">
                    {WEBHOOK_EVENTS.map((event) => (
                        <Badge key={event} tone="neutral">
                            <code>{event}</code>
                        </Badge>
                    ))}
                </div>

                <Field label="Endpoints (URLs)">
                    <div className="ir-flex ir-flex-col ir-gap-2">
                        {urls.map((url, index) => (
                            <div key={index} className="ir-flex ir-items-center ir-gap-2">
                                <span className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                                    <Webhook className="ir-size-4" />
                                </span>
                                <Input value={url} onChange={(event) => setUrl(index, event.target.value)} placeholder="https://tu-servicio.com/webhook" />
                                <button type="button" title="Quitar" onClick={() => removeUrl(index)} className="ir-shrink-0 ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-danger/10 hover:ir-text-danger">
                                    <Trash2 className="ir-size-4" />
                                </button>
                            </div>
                        ))}
                        <Button type="button" variant="ghost" size="sm" className="ir-self-start" onClick={addUrl}>
                            <Plus className="ir-size-3.5" />
                            Añadir endpoint
                        </Button>
                    </div>
                </Field>

                <Field label="Secreto de firma (opcional)" hint={agency.webhook_secret_set ? 'Ya hay un secreto guardado. Escribe uno nuevo para reemplazarlo.' : 'Se envía como cabecera de firma HMAC para que tu servicio verifique el origen.'}>
                    <Input
                        type="password"
                        autoComplete="off"
                        value={secret}
                        onChange={(event) => setSecret(event.target.value)}
                        placeholder={agency.webhook_secret_set ? '•••••••• (deja en blanco para conservar)' : 'Un secreto compartido'}
                    />
                </Field>

                <Field label="Slack (opcional)" hint="Pega la URL de un Incoming Webhook de Slack: recibirás un mensaje cuando se detecte una anomalía (caída de tráfico, pico de gasto, caída de conversiones…), se envíe un reporte, surja una oportunidad de venta, una conexión deje de funcionar o un cliente te haga una pregunta desde su informe.">
                    <Input
                        type="url"
                        value={slackUrl}
                        onChange={(event) => setSlackUrl(event.target.value)}
                        placeholder="https://hooks.slack.com/services/…"
                    />
                </Field>

                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                    <Button onClick={save} disabled={update.isPending}>
                        {update.isPending ? 'Guardando…' : 'Guardar webhooks'}
                    </Button>
                    <Button variant="outline" onClick={() => test.mutate()} disabled={test.isPending || agency.webhook_urls.length === 0} title={agency.webhook_urls.length === 0 ? 'Guarda al menos un endpoint primero' : 'Envía un evento de prueba a los endpoints guardados'}>
                        <Send className="ir-size-3.5" />
                        {test.isPending ? 'Enviando…' : 'Probar'}
                    </Button>
                    {update.isSuccess && <span className="ir-text-xs ir-text-success">Webhooks guardados.</span>}
                    {test.isSuccess && <span className="ir-text-xs ir-text-success">Evento de prueba enviado a {test.data?.sent ?? 0} endpoint(s).</span>}
                    {test.isError && <span className="ir-text-xs ir-text-danger">No se pudo enviar la prueba.</span>}
                </div>
                <p className="ir-text-xs ir-text-muted-foreground">La prueba usa los endpoints ya guardados: guarda antes de probar cambios.</p>
            </div>
        </Card>
    );
}

/* ------------------------------ Asistentes IA ------------------------------ */

/** Checked by default in the new-token form: everything except sending and deleting. */
const RISKY_ABILITIES = ['reports:send', 'delete'];

function CopyButton({ value }: { value: string }): ReactElement {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
                void navigator.clipboard.writeText(value).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1500);
                });
            }}
        >
            {copied ? <Check className="ir-size-3.5" /> : <Copy className="ir-size-3.5" />}
            {copied ? 'Copiado' : 'Copiar'}
        </Button>
    );
}

function CopyField({ value }: { value: string }): ReactElement {
    return (
        <div className="ir-flex ir-items-center ir-gap-2">
            <code className="ir-min-w-0 ir-flex-1 ir-truncate ir-rounded-md ir-border ir-bg-muted ir-px-2.5 ir-py-1.5 ir-text-xs">{value}</code>
            <CopyButton value={value} />
        </div>
    );
}

function formatDate(value: string | null): string {
    return value === null ? 'Nunca' : new Date(value).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });
}

function AssistantsSection(): ReactElement {
    const { data: user } = useAuthUser();
    const privileged = user?.role === 'owner' || user?.role === 'admin';
    const { data, isLoading } = useApiTokens(privileged);
    const create = useCreateApiToken();
    const revoke = useRevokeApiToken();
    const [name, setName] = useState('');
    const [selected, setSelected] = useState<string[] | null>(null);
    const [plain, setPlain] = useState<string | null>(null);

    if (!privileged) {
        return (
            <Card title="Asistentes de IA (MCP)">
                <p className="ir-text-sm ir-text-muted-foreground">
                    Conecta Claude, ChatGPT u otro asistente a Imagina Reports para pedirle reportes, anotar trabajo o revisar
                    fuentes con lenguaje natural. Solo un propietario o administrador de la agencia puede conectarlo.
                </p>
            </Card>
        );
    }

    if (isLoading || data === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>;
    }

    const abilities = selected ?? data.abilities.map((a) => a.value).filter((value) => !RISKY_ABILITIES.includes(value));
    const toggle = (value: string): void =>
        setSelected(abilities.includes(value) ? abilities.filter((item) => item !== value) : [...abilities, value]);
    const labelOf = (value: string): string => data.abilities.find((a) => a.value === value)?.label ?? value;

    const submit = (): void => {
        create.mutate(
            { name: name.trim(), abilities },
            {
                onSuccess: (result) => {
                    setPlain(result.plain_text_token);
                    setName('');
                    setSelected(null);
                },
            },
        );
    };

    return (
        <>
            <Card
                title="Conectar un asistente"
                description="Tu asistente puede consultar datos, preparar reportes y anotar trabajo. Cada cambio te lo muestra como propuesta y solo se aplica si lo confirmas."
            >
                <div className="ir-flex ir-flex-col ir-gap-4 ir-text-sm">
                    <Field label="URL del conector (MCP)">
                        <CopyField value={data.mcp_url} />
                    </Field>
                    <div className="ir-flex ir-flex-col ir-gap-2">
                        <p className="ir-font-medium">Claude.ai o ChatGPT</p>
                        <p className="ir-text-muted-foreground">
                            En Claude: Ajustes → Conectores → «Añadir conector personalizado» y pega la URL. En ChatGPT: activa el
                            modo desarrollador en los ajustes de conectores y crea uno nuevo con la URL. Te pedirá entrar a Imagina
                            Reports y elegir qué puede hacer; no necesitas crear un token.
                        </p>
                    </div>
                    <div className="ir-flex ir-flex-col ir-gap-2">
                        <p className="ir-font-medium">Claude Code, Cursor u otros clientes</p>
                        <p className="ir-text-muted-foreground">Crea un token abajo y úsalo como cabecera. Por ejemplo, en Claude Code:</p>
                        <CopyField value={`claude mcp add --transport http imagina-reports ${data.mcp_url} --header "Authorization: Bearer TU_TOKEN"`} />
                    </div>
                </div>
            </Card>

            <Card title="Crear un token" description="Para clientes que no conectan solos. El token solo se muestra una vez.">
                {plain !== null ? (
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <p className="ir-text-sm">Copia el token ahora: no se volverá a mostrar.</p>
                        <CopyField value={plain} />
                        <Button variant="ghost" className="ir-self-start" onClick={() => setPlain(null)}>
                            Listo
                        </Button>
                    </div>
                ) : (
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        <Field label="Nombre">
                            <Input value={name} maxLength={80} onChange={(event) => setName(event.target.value)} placeholder="Claude Code de Ana" />
                        </Field>
                        <Field label="Qué podrá hacer">
                            <div className="ir-flex ir-flex-col ir-gap-1.5">
                                {data.abilities.map((ability) => (
                                    <label key={ability.value} className="ir-flex ir-items-start ir-gap-2 ir-text-sm">
                                        <input
                                            type="checkbox"
                                            className="ir-mt-0.5"
                                            checked={ability.value === 'read' || abilities.includes(ability.value)}
                                            disabled={ability.value === 'read'}
                                            onChange={() => toggle(ability.value)}
                                        />
                                        <span>{ability.label}</span>
                                    </label>
                                ))}
                            </div>
                        </Field>
                        <div className="ir-flex ir-items-center ir-gap-3">
                            <Button onClick={submit} disabled={create.isPending || name.trim() === ''}>
                                {create.isPending ? 'Creando…' : 'Crear token'}
                            </Button>
                            {create.isError && <span className="ir-text-xs ir-text-danger">No se pudo crear el token.</span>}
                        </div>
                    </div>
                )}
            </Card>

            <Card title="Asistentes conectados" description="Desconecta cualquiera en cualquier momento: deja de funcionar al instante.">
                {data.tokens.length === 0 ? (
                    <p className="ir-text-sm ir-text-muted-foreground">Todavía no hay asistentes conectados.</p>
                ) : (
                    <ul className="ir-flex ir-flex-col ir-divide-y">
                        {data.tokens.map((token) => (
                            <li key={token.id} className="ir-flex ir-items-start ir-gap-3 ir-py-3 first:ir-pt-0 last:ir-pb-0">
                                <span className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                                    <Bot className="ir-size-4" />
                                </span>
                                <div className="ir-min-w-0 ir-flex-1">
                                    <p className="ir-truncate ir-text-sm ir-font-medium">{token.name}</p>
                                    <p className="ir-text-xs ir-text-muted-foreground">
                                        {token.created_by !== null ? `De ${token.created_by} · ` : ''}Último uso: {formatDate(token.last_used_at)}
                                    </p>
                                    <div className="ir-mt-1.5 ir-flex ir-flex-wrap ir-gap-1">
                                        {token.abilities.map((ability) => (
                                            <Badge key={ability} tone={RISKY_ABILITIES.includes(ability) ? 'warning' : 'neutral'}>
                                                {labelOf(ability)}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={revoke.isPending}
                                    onClick={() => {
                                        if (window.confirm(`¿Desconectar «${token.name}»? Dejará de funcionar al instante.`)) {
                                            revoke.mutate(token.id);
                                        }
                                    }}
                                >
                                    <Trash2 className="ir-size-3.5" />
                                    Desconectar
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </>
    );
}

/* -------------------------------- Seguridad -------------------------------- */

const AUDIT_LABELS: Record<string, string> = {
    'team.deleted': 'Eliminó a un miembro',
    'sharing.updated': 'Cambió la privacidad de un reporte',
    'sharing.token_rotated': 'Rotó el enlace del panel',
    'report.deleted': 'Eliminó un reporte',
    'account.password_changed': 'Cambió su contraseña',
    'account.email_change_requested': 'Solicitó cambiar su email',
    'account.email_changed': 'Confirmó su nuevo email',
    'account.two_factor_enabled': 'Activó 2FA',
    'account.two_factor_disabled': 'Desactivó 2FA',
    'agency.deleted': 'Eliminó la agencia',
    'mcp.applied': 'Un asistente de IA aplicó un cambio',
    'api_token.created': 'Conectó un asistente de IA',
    'api_token.revoked': 'Desconectó un asistente de IA',
    'connect_invite.created': 'Creó un enlace de conexión para un cliente',
    'connect_invite.revoked': 'Desactivó un enlace de conexión',
    'connect_invite.used': 'El cliente conectó una cuenta desde su enlace',
};

function AuditLogCard(): ReactElement {
    const [page, setPage] = useState(1);
    const { data } = useAuditLogs(page, '');
    const entries = data?.data ?? [];
    const meta = data?.meta;

    return (
        <Card title="Registro de actividad" description="Quién hizo qué y cuándo. Solo lo ven propietario y administradores.">
            {entries.length === 0 ? (
                <p className="ir-text-sm ir-text-muted-foreground">Todavía no hay actividad registrada.</p>
            ) : (
                <ul className="ir-flex ir-flex-col ir-divide-y">
                    {entries.map((entry) => (
                        <li key={entry.id} className="ir-flex ir-flex-wrap ir-items-baseline ir-justify-between ir-gap-2 ir-py-2 ir-text-sm">
                            <div className="ir-min-w-0">
                                <p className="ir-font-medium">{entry.summary ?? AUDIT_LABELS[entry.action] ?? entry.action}</p>
                                <p className="ir-text-xs ir-text-muted-foreground">
                                    {entry.actor_name ?? 'Sistema'}{entry.actor_email != null ? ` · ${entry.actor_email}` : ''}{entry.ip != null ? ` · ${entry.ip}` : ''}
                                </p>
                            </div>
                            <span className="ir-shrink-0 ir-text-xs ir-text-muted-foreground">
                                {entry.created_at != null ? new Date(entry.created_at).toLocaleString() : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {meta != null && meta.last_page > 1 && (
                <div className="ir-mt-3 ir-flex ir-items-center ir-gap-2">
                    <Button size="sm" variant="ghost" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>← Anterior</Button>
                    <span className="ir-text-xs ir-text-muted-foreground">Página {meta.current_page} de {meta.last_page} · {meta.total} registros</span>
                    <Button size="sm" variant="ghost" onClick={() => setPage((p) => p + 1)} disabled={page >= meta.last_page}>Siguiente →</Button>
                </div>
            )}
        </Card>
    );
}

function DangerZoneCard({ agency }: { agency: AgencySettings }): ReactElement {
    const { data: user } = useAuthUser();
    const remove = useDeleteAgency();
    const [password, setPassword] = useState('');
    const [name, setName] = useState('');
    const [open, setOpen] = useState(false);

    if (user?.role !== 'owner') {
        return <></>;
    }

    const confirmDelete = (): void => {
        if (!window.confirm('Esto elimina la agencia y TODOS sus datos (clientes, sitios, reportes, usuarios). No se puede deshacer. ¿Continuar?')) {
            return;
        }
        remove.mutate(
            { current_password: password, confirm_name: name },
            { onSuccess: () => { window.location.hash = '#/'; window.location.reload(); } },
        );
    };

    return (
        <Card title="Zona de peligro" description="Eliminar la agencia borra de forma permanente todos sus datos. No hay vuelta atrás.">
            {!open ? (
                <Button variant="ghost" className="ir-self-start ir-text-danger" onClick={() => setOpen(true)}>
                    Eliminar mi agencia…
                </Button>
            ) : (
                <div className="ir-flex ir-flex-col ir-gap-3 ir-rounded-md ir-border ir-border-danger/40 ir-bg-danger/5 ir-p-3">
                    <p className="ir-text-xs ir-text-danger">
                        Se eliminarán clientes, sitios, fuentes, snapshots, reportes y usuarios. Descarga antes lo que necesites conservar.
                    </p>
                    <Field label="Contraseña actual">
                        <Input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                    </Field>
                    <Field label={`Escribe «${agency.name}» para confirmar`}>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={agency.name} />
                    </Field>
                    {remove.isError && <p className="ir-text-xs ir-text-danger">No se pudo eliminar. Revisa la contraseña y el nombre.</p>}
                    <div className="ir-flex ir-gap-2">
                        <Button onClick={confirmDelete} disabled={remove.isPending || password === '' || name.trim() !== agency.name.trim()}>
                            {remove.isPending ? 'Eliminando…' : 'Eliminar definitivamente'}
                        </Button>
                        <Button variant="ghost" onClick={() => setOpen(false)}>Cancelar</Button>
                    </div>
                </div>
            )}
        </Card>
    );
}

/* ---------------------------------- Screen --------------------------------- */

type SectionKey = 'account' | 'agency' | 'billing' | 'integrations' | 'assistants' | 'security';

/**
 * Settings used to be one long scroll of ten cards, which put the login email and the
 * MercadoPago payer email in the same stream — the single biggest source of support
 * confusion. They now live in separate sections, each with its own save button.
 */
const SECTIONS: { key: SectionKey; label: string; icon: typeof User; heading: string; blurb: string }[] = [
    { key: 'account', label: 'Mi cuenta', icon: User, heading: 'Mi cuenta', blurb: 'Tus datos personales: nombre, email de acceso, contraseña y verificación en dos pasos.' },
    { key: 'agency', label: 'Mi agencia', icon: Building2, heading: 'Mi agencia', blurb: 'Cómo se ve tu agencia en los reportes: nombre, idioma, color y logo.' },
    { key: 'billing', label: 'Plan y pagos', icon: CreditCard, heading: 'Plan y pagos', blurb: 'Tu consumo, tu suscripción y el email con el que se te cobra.' },
    { key: 'integrations', label: 'Integraciones', icon: Plug, heading: 'Integraciones', blurb: 'Clave de IA, avisos por correo, plugin del agente para WordPress, webhooks salientes y avisos por Slack.' },
    { key: 'assistants', label: 'Asistentes IA', icon: Bot, heading: 'Asistentes IA', blurb: 'Conecta Claude, ChatGPT u otro asistente para trabajar con Imagina Reports hablando.' },
    { key: 'security', label: 'Seguridad', icon: ShieldCheck, heading: 'Seguridad', blurb: 'Registro de actividad de tu equipo y eliminación de la cuenta.' },
];

export function SettingsScreen(): ReactElement {
    const { data: agency, isLoading } = useAgency();
    // Another screen (the account menu) can ask to open a given section.
    const [section, setSection] = useState<SectionKey>(() => {
        try {
            const requested = sessionStorage.getItem('ir-settings-section');
            sessionStorage.removeItem('ir-settings-section');

            return SECTIONS.some((item) => item.key === requested) ? (requested as SectionKey) : 'account';
        } catch {
            return 'account';
        }
    });

    if (isLoading || agency === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando ajustes…</p>;
    }

    const current = SECTIONS.find((item) => item.key === section);

    const content: Record<SectionKey, ReactNode> = {
        account: (
            <>
                <ProfileCard />
                <PasswordCard />
                <TwoFactorCard />
            </>
        ),
        agency: (
            <>
                <BrandingCard agency={agency} />
                <ContactCard agency={agency} />
                <DomainCard agency={agency} />
                <SenderCard agency={agency} />
            </>
        ),
        billing: (
            <>
                <PlanUsageCard agency={agency} />
                <BillingCard />
            </>
        ),
        integrations: (
            <>
                <AiCard agency={agency} />
                <NotifyCard agency={agency} />
                <SiteAgentPluginCard />
                <WebhooksCard agency={agency} />
            </>
        ),
        assistants: <AssistantsSection />,
        security: (
            <>
                <AuditLogCard />
                <DangerZoneCard agency={agency} />
            </>
        ),
    };

    return (
        <div className="ir-flex ir-flex-col">
            <PageHeader title="Ajustes" description="Tu cuenta, tu agencia, el plan y las integraciones. Elige una sección para ver solo lo que necesitas cambiar." />

            <div className="ir-flex ir-flex-col ir-gap-6 lg:ir-flex-row lg:ir-gap-8">
                {/* Pills on narrow screens, a settings sidebar from lg up. */}
                <nav className="ir-flex ir-gap-1 ir-overflow-x-auto ir-pb-1 lg:ir-w-56 lg:ir-shrink-0 lg:ir-flex-col lg:ir-overflow-visible lg:ir-pb-0">
                    {SECTIONS.map(({ key, label, icon: Icon }) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setSection(key)}
                            className={`ir-inline-flex ir-shrink-0 ir-items-center ir-gap-2 ir-rounded-md ir-px-3 ir-py-2 ir-text-sm ir-font-medium ir-transition-colors lg:ir-w-full ${
                                section === key
                                    ? 'ir-bg-card ir-text-foreground ir-shadow-ir-xs ir-ring-1 ir-ring-border'
                                    : 'ir-text-muted-foreground hover:ir-bg-muted/50 hover:ir-text-foreground'
                            }`}
                        >
                            <Icon className="ir-size-4" />
                            {label}
                        </button>
                    ))}
                </nav>

                {/* Forms read best at a comfortable width, even on a very wide screen. */}
                <div className="ir-flex ir-min-w-0 ir-max-w-4xl ir-flex-1 ir-flex-col ir-gap-4">
                    {current !== undefined && (
                        <div>
                            <h2 className="ir-text-base ir-font-semibold ir-tracking-tight">{current.heading}</h2>
                            <p className="ir-mt-0.5 ir-text-sm ir-text-muted-foreground">{current.blurb}</p>
                        </div>
                    )}
                    {content[section]}
                </div>
            </div>
        </div>
    );
}
