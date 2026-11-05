import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Building2,
    Check,
    CreditCard,
    Database,
    DownloadCloud,
    Gauge,
    KeyRound,
    LayoutGrid,
    LogIn,
    Pencil,
    Plug,
    Plus,
    Power,
    Search,
    ShieldCheck,
    Trash2,
    Users,
} from 'lucide-react';
import { type FormEvent, type ReactElement, type ReactNode, useEffect, useMemo, useState } from 'react';

import {
    usePlatformBillingSettings,
    usePlatformIntegrations,
    usePlatformMailSettings,
    useTestPlatformMail,
    useUpdatePlatformBillingSettings,
    useUpdatePlatformIntegrations,
    useTestPlatformAi,
    useUpdatePlatformMailSettings,
} from '../api';
import { GoogleAdsSetupGuide, GoogleSetupGuide, MercadoPagoWebhookGuide, MetaSetupGuide, PayPalWebhookGuide } from '../components/OAuthSetupGuide';
import { SystemUpdatePanel } from '../components/SystemUpdatePanel';

import {
    type PlanInput,
    useCreatePlan,
    useCreatePlatformAgency,
    useCreatePlatformUser,
    useDeletePlan,
    useDeletePlatformAgency,
    useDeletePlatformUser,
    useImpersonateAgency,
    usePlatformAgencies,
    usePlatformAgency,
    usePlatformOverview,
    usePlatformPlans,
    useUpdatePlan,
    useUpdatePlatformAgency,
    useUpdatePlatformUser,
} from '../api';
import { ActionMenu, Avatar, Badge, Button, Card, Field, Input, Modal, PageHeader, Select, StatCard, StatusDot } from '../components/ui';
import { type PlatformTab, useAdminUi } from '../store';
import type { Plan, PlatformAgency, PlatformAgencyUser } from '../types';

// Currencies MercadoPago (local) + PayPal (USD) commonly support in the region.
const CURRENCIES = ['USD', 'ARS', 'MXN', 'COP', 'CLP', 'PEN', 'BRL', 'UYU', 'EUR'];

const FEATURES: { key: string; label: string }[] = [
    { key: 'ai_builder', label: 'Generador con IA' },
    { key: 'white_label', label: 'Marca blanca' },
    { key: 'remove_branding', label: 'Quitar «powered by»' },
    { key: 'custom_domain', label: 'Dominio propio' },
];

const ROLE_LABELS: Record<PlatformAgencyUser['role'], string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    collaborator: 'Colaborador',
};

const numberFmt = new Intl.NumberFormat('es');

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

/* ------------------------------- Primitives -------------------------------- */

/** A single headline number — the same card the agency app uses. */
function Stat({
    label,
    value,
    hint,
    icon,
    tone = 'neutral',
}: {
    label: string;
    value: string | number;
    hint?: string;
    icon: typeof Building2;
    tone?: 'neutral' | 'danger' | 'success';
}): ReactElement {
    return <StatCard label={label} value={value} hint={hint} icon={icon} tone={tone} />;
}

/** A used/limit progress bar (null limit = ilimitado). */
function UsageBar({ label, used, limit }: { label: string; used: number; limit: number | null }): ReactElement {
    const pct = limit === null || limit === 0 ? 0 : Math.min(100, (used / limit) * 100);
    const over = limit !== null && used >= limit;

    return (
        <div>
            <div className="ir-flex ir-justify-between ir-text-[11px] ir-text-muted-foreground">
                <span>{label}</span>
                <span className={over ? 'ir-font-medium ir-text-danger' : 'ir-tabular-nums'}>
                    {used}/{limit ?? '∞'}
                </span>
            </div>
            <div className="ir-mt-0.5 ir-h-1.5 ir-overflow-hidden ir-rounded ir-bg-muted">
                <div className={`ir-h-full ir-rounded ${over ? 'ir-bg-danger' : 'ir-bg-primary'}`} style={{ width: `${limit === null ? 6 : pct}%` }} />
            </div>
        </div>
    );
}

/** A destructive action that requires retyping an exact confirmation phrase. */
function ConfirmByName({
    phrase,
    label,
    busy,
    onConfirm,
}: {
    phrase: string;
    label: string;
    busy: boolean;
    onConfirm: () => void;
}): ReactElement {
    const [typed, setTyped] = useState('');

    return (
        <div className="ir-flex ir-flex-col ir-gap-2 sm:ir-flex-row sm:ir-items-end">
            <div className="ir-flex-1">
                <Field label={`Escribe «${phrase}» para confirmar`}>
                    <Input value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={phrase} />
                </Field>
            </div>
            <Button variant="danger" disabled={busy || typed.trim() !== phrase.trim()} onClick={onConfirm}>
                <Trash2 className="ir-size-3.5" />
                {busy ? 'Eliminando…' : label}
            </Button>
        </div>
    );
}

/** A titled block inside the agency detail panel. */
function DetailSection({ title, description, children }: { title: string; description?: string; children: ReactNode }): ReactElement {
    return (
        <section className="ir-border-t ir-px-5 ir-py-4 first:ir-border-t-0">
            <h3 className="ir-text-sm ir-font-semibold ir-tracking-tight">{title}</h3>
            {description !== undefined && <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">{description}</p>}
            <div className="ir-mt-3">{children}</div>
        </section>
    );
}

/* --------------------------------- Overview -------------------------------- */

/** A flat section heading — the overview is a grid of numbers, not a stack of nested cards. */
function GroupHeading({ title, action }: { title: string; action?: ReactNode }): ReactElement {
    return (
        <div className="ir-flex ir-items-center ir-justify-between ir-gap-3">
            <h2 className="ir-text-[13px] ir-font-semibold ir-text-foreground">{title}</h2>
            {action}
        </div>
    );
}

function OverviewTab({ onOpenAgencies }: { onOpenAgencies: () => void }): ReactElement {
    const { data, isLoading } = usePlatformOverview();
    const { data: agencies = [] } = usePlatformAgencies();
    // The busiest agencies first: who to look after.
    const busiest = [...agencies].sort((a, b) => b.usage.reports_this_month - a.usage.reports_this_month || b.usage.sites - a.usage.sites).slice(0, 6);

    if (isLoading || data === undefined) {
        return (
            <>
                <SectionHeader tab="overview" />
                <p className="ir-text-sm ir-text-muted-foreground">Cargando el estado de la plataforma…</p>
            </>
        );
    }

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <SectionHeader
                tab="overview"
                action={
                    <Button variant="ghost" onClick={onOpenAgencies}>
                        <Building2 className="ir-size-4" />
                        Gestionar agencias
                    </Button>
                }
            />
            <div className="ir-flex ir-flex-col ir-gap-2.5">
                <GroupHeading title="Agencias y personas" />
                <div className="ir-grid ir-grid-cols-2 ir-gap-3 lg:ir-grid-cols-4">
                    <Stat icon={Building2} label="Agencias" value={numberFmt.format(data.agencies.total)} hint={`${data.agencies.active} activas · ${data.agencies.suspended} suspendidas`} />
                    <Stat icon={Activity} label="Nuevas (30 días)" value={numberFmt.format(data.agencies.new_this_month)} hint="Altas recientes" />
                    <Stat icon={Users} label="Usuarios" value={numberFmt.format(data.users.total)} hint={`${data.users.with_two_factor} con 2FA activo`} />
                    <Stat
                        icon={AlertTriangle}
                        label="Fuentes con error"
                        value={numberFmt.format(data.health.failing_sources)}
                        hint={data.health.failing_sources > 0 ? 'Requieren soporte' : 'Todo conectado'}
                        tone={data.health.failing_sources > 0 ? 'danger' : 'success'}
                    />
                </div>
            </div>

            <div className="ir-flex ir-flex-col ir-gap-2.5">
                <GroupHeading title="Carga de trabajo" />
                <div className="ir-grid ir-grid-cols-2 ir-gap-3 lg:ir-grid-cols-4">
                    <Stat icon={Users} label="Clientes" value={numberFmt.format(data.workload.clients)} />
                    <Stat icon={Gauge} label="Sitios" value={numberFmt.format(data.workload.sites)} />
                    <Stat icon={Plug} label="Fuentes de datos" value={numberFmt.format(data.workload.data_sources)} />
                    <Stat icon={LayoutGrid} label="Reportes este mes" value={numberFmt.format(data.workload.reports_this_month)} />
                </div>
            </div>

            <div className="ir-flex ir-flex-col ir-gap-2.5">
                <GroupHeading title="Almacenamiento y cobros" />
                <div className="ir-grid ir-grid-cols-2 ir-gap-3 lg:ir-grid-cols-4">
                    <Stat icon={Database} label="Snapshots" value={numberFmt.format(data.health.snapshots)} hint="Datos ya agregados por los conectores" />
                    <Stat icon={Database} label="Tamaño de los datos" value={formatBytes(data.health.storage_bytes)} />
                    <Stat icon={CreditCard} label="Suscripciones activas" value={numberFmt.format(data.billing.active_subscriptions)} tone={data.billing.active_subscriptions > 0 ? 'success' : 'neutral'} />
                    <Stat
                        icon={AlertTriangle}
                        label="Pendientes / vencidas"
                        value={numberFmt.format(data.billing.past_due)}
                        hint={data.billing.past_due > 0 ? 'Revisa el cobro' : 'Sin incidencias'}
                        tone={data.billing.past_due > 0 ? 'danger' : 'neutral'}
                    />
                </div>
            </div>

            {busiest.length > 0 && (
                <Card
                    title="Agencias más activas"
                    description="Por reportes generados este mes."
                    actions={
                        <Button variant="quiet" size="sm" onClick={onOpenAgencies}>
                            Ver todas
                            <ArrowRight className="ir-size-3.5" />
                        </Button>
                    }
                >
                    <div className="ir-flex ir-flex-col ir-divide-y">
                        {busiest.map((agency) => (
                            <div key={agency.id} className="ir-flex ir-items-center ir-gap-3 ir-py-2.5">
                                <Avatar name={agency.name} />
                                <div className="ir-min-w-0 ir-flex-1">
                                    <p className="ir-truncate ir-text-sm ir-font-medium">{agency.name}</p>
                                    <p className="ir-truncate ir-text-xs ir-text-muted-foreground">
                                        {agency.plan?.name ?? 'Sin plan'} · {agency.usage.sites} sitios · {agency.usage.reports_this_month} reportes este mes
                                    </p>
                                </div>
                                <Badge tone={agency.status === 'suspended' ? 'danger' : 'success'}>{agency.status === 'suspended' ? 'Suspendida' : 'Activa'}</Badge>
                            </div>
                        ))}
                    </div>
                </Card>
            )}
        </div>
    );
}

/* ---------------------------------- Agencies ------------------------------- */

function CreateAgencyModal({ plans, onClose }: { plans: Plan[]; onClose: () => void }): ReactElement {
    const create = useCreatePlatformAgency();
    const [form, setForm] = useState({ name: '', plan_id: '', owner_name: '', owner_email: '', owner_password: '' });
    const set = (key: keyof typeof form, value: string): void => setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        create.mutate(
            {
                name: form.name.trim(),
                plan_id: form.plan_id === '' ? null : Number(form.plan_id),
                owner_name: form.owner_name.trim(),
                owner_email: form.owner_email.trim(),
                owner_password: form.owner_password,
            },
            { onSuccess: onClose },
        );
    };

    return (
        <Modal onClose={onClose}>
            <Card title="Nueva agencia" description="Crea la agencia y su usuario propietario. Recibirán acceso con estas credenciales." actions={<Button variant="ghost" size="sm" onClick={onClose}>Cerrar</Button>}>
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Nombre de la agencia">
                        <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Agencia Acme" />
                    </Field>
                    <Field label="Plan">
                        <Select value={form.plan_id} onChange={(e) => set('plan_id', e.target.value)}>
                            <option value="">Sin plan (ilimitado)</option>
                            {plans.map((plan) => (
                                <option key={plan.id} value={plan.id}>
                                    {plan.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="Nombre del propietario">
                            <Input value={form.owner_name} onChange={(e) => set('owner_name', e.target.value)} />
                        </Field>
                        <Field label="Email del propietario">
                            <Input type="email" value={form.owner_email} onChange={(e) => set('owner_email', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Contraseña (mín. 8)">
                        <Input type="text" value={form.owner_password} onChange={(e) => set('owner_password', e.target.value)} placeholder="Se la compartes al propietario" />
                    </Field>
                    {create.isError && <p className="ir-text-xs ir-text-danger">No se pudo crear. Revisa el email (¿ya existe?) y la contraseña.</p>}
                    <div className="ir-mt-1 ir-flex ir-justify-end ir-gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={create.isPending || form.name.trim() === '' || form.owner_email.trim() === '' || form.owner_password.length < 8}>
                            {create.isPending ? 'Creando…' : 'Crear agencia'}
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

/** Add a person to an agency without leaving the detail panel. */
function AddUserForm({ agencyId, onDone }: { agencyId: number; onDone: () => void }): ReactElement {
    const create = useCreatePlatformUser();
    const [form, setForm] = useState({ name: '', email: '', password: '', role: 'collaborator' });
    const set = (key: keyof typeof form, value: string): void => setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        create.mutate({ agencyId, ...form, name: form.name.trim(), email: form.email.trim() }, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="ir-mt-3 ir-flex ir-flex-col ir-gap-3 ir-rounded-md ir-border ir-bg-muted/40 ir-p-3">
            <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                <Field label="Nombre">
                    <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
                </Field>
                <Field label="Email">
                    <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
                </Field>
                <Field label="Contraseña (mín. 8)">
                    <Input type="text" value={form.password} onChange={(e) => set('password', e.target.value)} placeholder="Se la compartes a la persona" />
                </Field>
                <Field label="Rol">
                    <Select value={form.role} onChange={(e) => set('role', e.target.value)}>
                        {(Object.keys(ROLE_LABELS) as PlatformAgencyUser['role'][]).map((role) => (
                            <option key={role} value={role}>
                                {ROLE_LABELS[role]}
                            </option>
                        ))}
                    </Select>
                </Field>
            </div>
            {create.isError && <p className="ir-text-xs ir-text-danger">No se pudo crear. ¿El email ya está en uso?</p>}
            <div className="ir-flex ir-justify-end ir-gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>Cancelar</Button>
                <Button type="submit" size="sm" disabled={create.isPending || form.name.trim() === '' || form.email.trim() === '' || form.password.length < 8}>
                    {create.isPending ? 'Añadiendo…' : 'Añadir usuario'}
                </Button>
            </div>
        </form>
    );
}

/** One person's row: role change, password reset and removal, all inline. */
function UserRow({ agencyId, user }: { agencyId: number; user: PlatformAgencyUser }): ReactElement {
    const update = useUpdatePlatformUser();
    const remove = useDeletePlatformUser();
    const [resetting, setResetting] = useState(false);
    const [password, setPassword] = useState('');

    const applyPassword = (): void => {
        update.mutate({ agencyId, id: user.id, password }, {
            onSuccess: () => {
                setPassword('');
                setResetting(false);
            },
        });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-2 ir-border-b ir-border-border/60 ir-py-2.5 last:ir-border-b-0">
            <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-2">
                <div className="ir-min-w-0">
                    <p className="ir-flex ir-items-center ir-gap-1.5 ir-text-sm ir-font-medium">
                        {user.name}
                        {user.two_factor_enabled && (
                            <Badge tone="success">
                                <ShieldCheck className="ir-size-3" />
                                2FA
                            </Badge>
                        )}
                    </p>
                    <p className="ir-truncate ir-text-xs ir-text-muted-foreground">{user.email}</p>
                </div>
                <div className="ir-flex ir-items-center ir-gap-1.5">
                    <Select
                        className="ir-h-8 ir-w-36 ir-text-xs"
                        value={user.role}
                        disabled={update.isPending}
                        onChange={(e) => update.mutate({ agencyId, id: user.id, role: e.target.value })}
                    >
                        {(Object.keys(ROLE_LABELS) as PlatformAgencyUser['role'][]).map((role) => (
                            <option key={role} value={role}>
                                {ROLE_LABELS[role]}
                            </option>
                        ))}
                    </Select>
                    <Button variant="ghost" size="sm" title="Restablecer contraseña" onClick={() => setResetting((prev) => !prev)}>
                        <KeyRound className="ir-size-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        title="Eliminar usuario"
                        disabled={remove.isPending}
                        onClick={() => {
                            if (window.confirm(`¿Eliminar a ${user.name} (${user.email})? Esta acción no se puede deshacer.`)) {
                                remove.mutate({ agencyId, id: user.id });
                            }
                        }}
                    >
                        <Trash2 className="ir-size-3.5 ir-text-danger" />
                    </Button>
                </div>
            </div>
            {resetting && (
                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-rounded-md ir-bg-muted/50 ir-p-2">
                    <Input
                        className="ir-h-8 ir-flex-1 ir-text-xs"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Nueva contraseña (mín. 8) — se la comunicas tú"
                    />
                    <Button size="sm" disabled={update.isPending || password.length < 8} onClick={applyPassword}>
                        Guardar contraseña
                    </Button>
                </div>
            )}
            {(update.isError || remove.isError) && (
                <p className="ir-text-xs ir-text-danger">No se pudo aplicar el cambio. La agencia debe conservar al menos un propietario.</p>
            )}
        </div>
    );
}

/** Fix an agency's name (typos at sign-up are the operator's most common support ticket). */
function RenameAgency({ id, name }: { id: number; name: string }): ReactElement {
    const update = useUpdatePlatformAgency();
    const [value, setValue] = useState(name);

    return (
        <div className="ir-flex ir-flex-wrap ir-items-end ir-gap-2">
            <div className="ir-min-w-[12rem] ir-flex-1">
                <Field label="Nombre de la agencia">
                    <Input value={value} onChange={(e) => setValue(e.target.value)} />
                </Field>
            </div>
            <Button variant="ghost" disabled={update.isPending || value.trim() === '' || value === name} onClick={() => update.mutate({ id, name: value.trim() })}>
                Guardar nombre
            </Button>
        </div>
    );
}

function AgencyDetailModal({ agencyId, plans, onClose }: { agencyId: number; plans: Plan[]; onClose: () => void }): ReactElement {
    const { data: agency, isLoading } = usePlatformAgency(agencyId);
    const update = useUpdatePlatformAgency();
    const remove = useDeletePlatformAgency();
    const impersonate = useImpersonateAgency();
    const setView = useAdminUi((state) => state.setView);
    const [addingUser, setAddingUser] = useState(false);

    const suspended = agency?.status === 'suspended';

    return (
        <Modal onClose={onClose} className="ir-max-w-3xl">
            <Card
                title={agency?.name ?? 'Agencia'}
                description={agency !== undefined ? `/${agency.slug} · alta ${agency.created_at?.slice(0, 10) ?? '—'}` : undefined}
                actions={
                    <>
                        <Button
                            variant="accent"
                            size="sm"
                            disabled={impersonate.isPending}
                            // Always land on the same section of the agency. The store keeps
                            // whatever view was last open, so without this you'd enter agency
                            // B on whatever screen you happened to leave agency A.
                            onClick={() => impersonate.mutate(agencyId, { onSuccess: () => setView('clients') })}
                        >
                            <LogIn className="ir-size-3.5" />
                            Entrar
                        </Button>
                        <Button variant="ghost" size="sm" onClick={onClose}>Cerrar</Button>
                    </>
                }
            >
                {isLoading || agency === undefined ? (
                    <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
                ) : (
                    // -m-5 cancels Card's padding so each section can own its own spacing.
                    <div className="-ir-m-5">
                        <DetailSection title="Identidad">
                            <RenameAgency id={agency.id} name={agency.name} />
                        </DetailSection>

                        <DetailSection title="Estado y plan">
                            <div className="ir-flex ir-flex-wrap ir-items-end ir-gap-3">
                                <div className="ir-min-w-[12rem] ir-flex-1">
                                    <Field label="Plan">
                                        <Select
                                            value={agency.plan_id ?? ''}
                                            onChange={(e) => update.mutate({ id: agency.id, plan_id: e.target.value === '' ? null : Number(e.target.value) })}
                                        >
                                            <option value="">Sin plan (ilimitado)</option>
                                            {plans.map((plan) => (
                                                <option key={plan.id} value={plan.id}>
                                                    {plan.name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                                <Button
                                    variant={suspended ? 'primary' : 'outline'}
                                    onClick={() => update.mutate({ id: agency.id, status: suspended ? 'active' : 'suspended' })}
                                    disabled={update.isPending}
                                >
                                    <Power className="ir-size-3.5" />
                                    {suspended ? 'Reactivar agencia' : 'Suspender agencia'}
                                </Button>
                                <Badge tone={suspended ? 'danger' : 'success'}>{suspended ? 'Suspendida' : 'Activa'}</Badge>
                            </div>
                            {agency.subscription !== null && (
                                <p className="ir-mt-3 ir-text-xs ir-text-muted-foreground">
                                    Suscripción {agency.subscription.provider} · <span className="ir-font-medium ir-text-foreground">{agency.subscription.status}</span>
                                    {agency.subscription.current_period_end !== null && ` · hasta ${agency.subscription.current_period_end.slice(0, 10)}`}
                                </p>
                            )}
                        </DetailSection>

                        <DetailSection title="Uso frente a los límites del plan">
                            <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-3">
                                <UsageBar label="Sitios" used={agency.usage.sites} limit={agency.limits.max_sites} />
                                <UsageBar label="Fuentes" used={agency.usage.data_sources} limit={agency.limits.max_data_sources} />
                                <UsageBar label="Clientes" used={agency.usage.clients} limit={agency.limits.max_clients} />
                                <UsageBar label="Usuarios" used={agency.usage.users} limit={agency.limits.max_users} />
                                <UsageBar label="Reportes/mes" used={agency.usage.reports_this_month} limit={agency.limits.max_reports_per_month} />
                            </div>
                        </DetailSection>

                        <DetailSection title={`Usuarios (${agency.users.length})`} description="Añade personas, corrige roles o restablece contraseñas sin pedirle nada a la agencia.">
                            <div className="ir-flex ir-flex-col">
                                {agency.users.map((user) => (
                                    <UserRow key={user.id} agencyId={agency.id} user={user} />
                                ))}
                            </div>
                            {addingUser ? (
                                <AddUserForm agencyId={agency.id} onDone={() => setAddingUser(false)} />
                            ) : (
                                <Button variant="ghost" size="sm" className="ir-mt-3" onClick={() => setAddingUser(true)}>
                                    <Plus className="ir-size-3.5" />
                                    Añadir usuario
                                </Button>
                            )}
                        </DetailSection>

                        <DetailSection title="Zona de peligro" description="Eliminar la agencia borra de forma permanente sus clientes, sitios, fuentes, reportes y usuarios. No hay vuelta atrás.">
                            <ConfirmByName
                                phrase={agency.name}
                                label="Eliminar agencia"
                                busy={remove.isPending}
                                onConfirm={() =>
                                    remove.mutate({ id: agency.id, confirm_name: agency.name }, { onSuccess: onClose })
                                }
                            />
                            {remove.isError && <p className="ir-mt-2 ir-text-xs ir-text-danger">No se pudo eliminar. Vuelve a intentarlo.</p>}
                        </DetailSection>
                    </div>
                )}
            </Card>
        </Modal>
    );
}

function AgenciesTab(): ReactElement {
    const { data: agencies = [], isLoading } = usePlatformAgencies();
    const impersonate = useImpersonateAgency();
    const setView = useAdminUi((state) => state.setView);
    const { data: plans = [] } = usePlatformPlans();
    const [creating, setCreating] = useState(false);
    const [openId, setOpenId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const visible = useMemo((): PlatformAgency[] => {
        const term = search.trim().toLowerCase();

        return agencies.filter((agency) => {
            if (status !== '' && agency.status !== status) return false;
            if (term === '') return true;

            return agency.name.toLowerCase().includes(term) || agency.slug.toLowerCase().includes(term);
        });
    }, [agencies, search, status]);

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <SectionHeader
                tab="agencies"
                action={
                    <Button onClick={() => setCreating(true)}>
                        <Plus className="ir-size-4" />
                        Nueva agencia
                    </Button>
                }
            />
            <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                <div className="ir-relative ir-min-w-[14rem] ir-flex-1">
                    <Search className="ir-pointer-events-none ir-absolute ir-left-2.5 ir-top-1/2 ir-size-4 -ir-translate-y-1/2 ir-text-muted-foreground" />
                    <Input className="ir-pl-8" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar por nombre o slug…" />
                </div>
                <Select className="ir-w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">Todos los estados</option>
                    <option value="active">Activas</option>
                    <option value="suspended">Suspendidas</option>
                </Select>
            </div>

            {/* Plain surface rather than <Card>: the table owns its own edge-to-edge padding. */}
            <div className="ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-xs">
                <div className="ir-overflow-x-auto">
                    <table className="ir-w-full ir-border-collapse ir-text-left ir-text-sm">
                        <thead>
                            <tr className="ir-border-b ir-bg-muted/40">
                                {['Agencia', 'Plan', 'Estado', 'Uso', ''].map((header, index) => (
                                    <th
                                        key={header === '' ? `col-${index}` : header}
                                        // On a phone: agency, status and actions; plan from sm, usage from lg.
                                        className={`ir-whitespace-nowrap ir-px-3 ir-py-2.5 ir-text-xs ir-font-medium ir-text-muted-foreground first:ir-pl-5 last:ir-pr-5 ${header === 'Plan' ? 'ir-hidden sm:ir-table-cell' : header === 'Uso' ? 'ir-hidden lg:ir-table-cell' : ''}`}
                                    >
                                        {header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((agency) => (
                                <tr
                                    key={agency.id}
                                    className="ir-cursor-pointer ir-border-b ir-border-border/60 ir-transition-colors hover:ir-bg-muted/50"
                                    onClick={() => setOpenId(agency.id)}
                                >
                                    <td className="ir-px-3 ir-py-3 first:ir-pl-5">
                                        <div className="ir-flex ir-items-center ir-gap-3">
                                            <Avatar name={agency.name} />
                                            <div className="ir-min-w-0">
                                                <p className="ir-truncate ir-font-medium">{agency.name}</p>
                                                <p className="ir-truncate ir-text-xs ir-text-muted-foreground">
                                                    /{agency.slug}
                                                    {agency.domain != null && agency.domain !== '' && ` · ${agency.domain}`}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="ir-hidden ir-px-3 ir-py-3 sm:ir-table-cell">
                                        {agency.plan !== null ? <Badge tone="accent">{agency.plan.name}</Badge> : <span className="ir-text-xs ir-text-muted-foreground">Sin plan</span>}
                                    </td>
                                    <td className="ir-px-3 ir-py-3">
                                        <Badge tone={agency.status === 'suspended' ? 'danger' : 'success'}>{agency.status === 'suspended' ? 'Suspendida' : 'Activa'}</Badge>
                                    </td>
                                    <td className="ir-hidden ir-w-72 ir-px-3 ir-py-3 lg:ir-table-cell">
                                        <div className="ir-grid ir-grid-cols-2 ir-gap-x-4 ir-gap-y-1.5">
                                            <UsageBar label="Sitios" used={agency.usage.sites} limit={agency.limits.max_sites} />
                                            <UsageBar label="Reportes/mes" used={agency.usage.reports_this_month} limit={agency.limits.max_reports_per_month} />
                                        </div>
                                    </td>
                                    <td className="ir-px-3 ir-py-3 last:ir-pr-5" onClick={(event) => event.stopPropagation()}>
                                        <div className="ir-flex ir-items-center ir-justify-end ir-gap-1">
                                            <Button variant="ghost" size="sm" onClick={() => setOpenId(agency.id)}>
                                                Gestionar
                                            </Button>
                                            <ActionMenu
                                                label={`Acciones de ${agency.name}`}
                                                items={[
                                                    { label: 'Entrar como esta agencia', icon: LogIn, onSelect: () => impersonate.mutate(agency.id, { onSuccess: () => setView('clients') }) },
                                                    { label: 'Gestionar plan y usuarios', icon: Pencil, onSelect: () => setOpenId(agency.id) },
                                                ]}
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {visible.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="ir-px-5 ir-py-10 ir-text-center ir-text-sm ir-text-muted-foreground">
                                        {isLoading ? 'Cargando…' : agencies.length === 0 ? 'Aún no hay agencias. Crea la primera.' : 'Ninguna agencia coincide con el filtro.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {creating && <CreateAgencyModal plans={plans} onClose={() => setCreating(false)} />}
            {openId !== null && <AgencyDetailModal agencyId={openId} plans={plans} onClose={() => setOpenId(null)} />}
        </div>
    );
}

/* ----------------------------------- Plans --------------------------------- */

type NumericPlanKey = 'max_sites' | 'max_data_sources' | 'max_clients' | 'max_users' | 'max_reports_per_month' | 'retention_months';

const NUM_FIELDS: { key: NumericPlanKey; label: string }[] = [
    { key: 'max_sites', label: 'Sitios' },
    { key: 'max_data_sources', label: 'Fuentes' },
    { key: 'max_clients', label: 'Clientes' },
    { key: 'max_users', label: 'Usuarios' },
    { key: 'max_reports_per_month', label: 'Reportes/mes' },
    { key: 'retention_months', label: 'Retención (meses)' },
];

function PlanModal({ plan, onClose }: { plan: Plan | null; onClose: () => void }): ReactElement {
    const create = useCreatePlan();
    const update = useUpdatePlan();
    const [name, setName] = useState(plan?.name ?? '');
    const [price, setPrice] = useState(plan?.monthly_price != null ? String(plan.monthly_price) : '');
    const [currency, setCurrency] = useState(plan?.currency ?? 'USD');
    const [limits, setLimits] = useState<Record<string, string>>(
        Object.fromEntries(NUM_FIELDS.map((f) => [f.key, plan?.[f.key] != null ? String(plan[f.key]) : ''])),
    );
    const [features, setFeatures] = useState<Record<string, boolean>>(
        Object.fromEntries(FEATURES.map((f) => [f.key, plan?.features?.[f.key] ?? false])),
    );

    const numOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value));

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        const payload: PlanInput = { name: name.trim(), monthly_price: numOrNull(price), currency, features };
        for (const field of NUM_FIELDS) {
            payload[field.key] = numOrNull(limits[field.key] ?? '');
        }
        if (plan !== null) {
            update.mutate({ id: plan.id, ...payload }, { onSuccess: onClose });
        } else {
            create.mutate(payload, { onSuccess: onClose });
        }
    };

    return (
        <Modal onClose={onClose}>
            <Card title={plan !== null ? `Editar plan · ${plan.name}` : 'Nuevo plan'} description="Deja un límite en blanco para «ilimitado»." actions={<Button variant="ghost" size="sm" onClick={onClose}>Cerrar</Button>}>
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="Nombre">
                            <Input value={name} onChange={(e) => setName(e.target.value)} />
                        </Field>
                        <div className="ir-grid ir-grid-cols-2 ir-gap-3">
                            <Field label="Precio mensual">
                                <Input type="number" min="0" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="49" />
                            </Field>
                            <Field label="Moneda">
                                <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
                                    {CURRENCIES.map((code) => (
                                        <option key={code} value={code}>
                                            {code}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </div>
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-3">
                        {NUM_FIELDS.map((f) => (
                            <Field key={f.key} label={f.label}>
                                <Input type="number" min="0" value={limits[f.key] ?? ''} onChange={(e) => setLimits((prev) => ({ ...prev, [f.key]: e.target.value }))} placeholder="∞" />
                            </Field>
                        ))}
                    </div>
                    <div>
                        <p className="ir-mb-1.5 ir-text-xs ir-font-medium ir-text-foreground/80">Funciones incluidas</p>
                        <div className="ir-grid ir-gap-2 sm:ir-grid-cols-2">
                            {FEATURES.map((f) => (
                                <label key={f.key} className="ir-flex ir-items-center ir-gap-2 ir-text-sm">
                                    <input type="checkbox" checked={features[f.key] ?? false} onChange={(e) => setFeatures((prev) => ({ ...prev, [f.key]: e.target.checked }))} />
                                    {f.label}
                                </label>
                            ))}
                        </div>
                    </div>
                    <div className="ir-mt-1 ir-flex ir-justify-end ir-gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={create.isPending || update.isPending || name.trim() === ''}>
                            {plan !== null ? 'Guardar' : 'Crear plan'}
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

function PlansTab(): ReactElement {
    const { data: plans = [], isLoading } = usePlatformPlans();
    const { data: agencies = [] } = usePlatformAgencies();
    const remove = useDeletePlan();
    const [editing, setEditing] = useState<Plan | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <SectionHeader
                tab="plans"
                action={
                    <Button onClick={() => setCreating(true)}>
                        <Plus className="ir-size-4" />
                        Nuevo plan
                    </Button>
                }
            />
            {isLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
            ) : plans.length === 0 ? (
                <Card>
                    <p className="ir-text-sm ir-text-muted-foreground">Aún no hay planes. Crea el primero para poder asignarlo a las agencias.</p>
                </Card>
            ) : (
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2 xl:ir-grid-cols-3 min-[1700px]:ir-grid-cols-4">
                    {plans.map((plan) => {
                        const using = agencies.filter((agency) => agency.plan?.name === plan.name).length;

                        return (
                            <section key={plan.id} className="ir-flex ir-flex-col ir-rounded-xl ir-border ir-bg-card ir-p-5 ir-shadow-ir-xs">
                                <div className="ir-flex ir-items-start ir-justify-between ir-gap-3">
                                    <div className="ir-min-w-0">
                                        <h2 className="ir-text-[15px] ir-font-semibold ir-tracking-tight">{plan.name}</h2>
                                        <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">
                                            {using === 1 ? '1 agencia lo usa' : `${using} agencias lo usan`}
                                        </p>
                                    </div>
                                    <ActionMenu
                                        label={`Acciones del plan ${plan.name}`}
                                        items={[
                                            { label: 'Editar plan', icon: Pencil, onSelect: () => setEditing(plan) },
                                            {
                                                label: 'Eliminar plan',
                                                icon: Trash2,
                                                danger: true,
                                                onSelect: () => {
                                                    if (window.confirm(`¿Eliminar el plan «${plan.name}»? Las agencias que lo usan quedarán sin plan.`)) remove.mutate(plan.id);
                                                },
                                            },
                                        ]}
                                    />
                                </div>
                                <p className="ir-mt-4 ir-flex ir-items-baseline ir-gap-1">
                                    {plan.monthly_price != null ? (
                                        <>
                                            <span className="ir-text-3xl ir-font-bold ir-tracking-tight ir-tabular-nums">{numberFmt.format(plan.monthly_price)}</span>
                                            <span className="ir-text-sm ir-text-muted-foreground">{plan.currency} / mes</span>
                                        </>
                                    ) : (
                                        <span className="ir-text-sm ir-text-muted-foreground">Sin precio</span>
                                    )}
                                </p>
                                <dl className="ir-mt-4 ir-grid ir-grid-cols-2 ir-gap-x-4 ir-gap-y-2 ir-border-t ir-pt-4 ir-text-[13px]">
                                    {NUM_FIELDS.map((f) => (
                                        <div key={f.key} className="ir-flex ir-items-baseline ir-justify-between ir-gap-2">
                                            <dt className="ir-text-muted-foreground">{f.label}</dt>
                                            <dd className="ir-font-medium ir-tabular-nums">{plan[f.key] ?? '∞'}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <ul className="ir-mt-4 ir-flex ir-flex-col ir-gap-1.5 ir-border-t ir-pt-4 ir-text-[13px]">
                                    {FEATURES.map((f) => {
                                        const included = plan.features?.[f.key] === true;

                                        return (
                                            <li key={f.key} className={`ir-flex ir-items-center ir-gap-2 ${included ? '' : 'ir-text-muted-foreground/60'}`}>
                                                {included ? <Check className="ir-size-3.5 ir-text-success" /> : <span className="ir-inline-block ir-size-3.5 ir-text-center ir-leading-none">–</span>}
                                                {f.label}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        );
                    })}
                </div>
            )}
            {creating && <PlanModal plan={null} onClose={() => setCreating(false)} />}
            {editing !== null && <PlanModal plan={editing} onClose={() => setEditing(null)} />}
        </div>
    );
}

/* --------------------------------- Billing --------------------------------- */

/** «configurado ✓» / «sin configurar», the same chip everywhere in this panel. */
function ConfigChip({ ready, label, note }: { ready: boolean; label?: string; note?: string }): ReactElement {
    const state = ready ? `listo${note !== undefined ? ` ${note}` : ''}` : 'sin configurar';
    const text = label !== undefined ? `${label}: ${state}` : state.charAt(0).toUpperCase() + state.slice(1);

    return (
        <span className={`ir-inline-flex ir-items-center ir-gap-1.5 ir-rounded-full ir-border ir-bg-card ir-px-2.5 ir-py-1 ir-text-xs ir-font-medium ${ready ? 'ir-text-success' : 'ir-text-warning'}`}>
            <StatusDot tone={ready ? 'success' : 'warning'} />
            {text}
        </span>
    );
}

/**
 * One provider's settings, Stripe/Vercel style: what it is and its status on the left, the
 * form on the right — instead of a stack of full-width cards with a save button per field.
 */
function SettingsRow({ title, description, status, children }: { title: string; description: ReactNode; status?: ReactNode; children: ReactNode }): ReactElement {
    return (
        <section className="ir-grid ir-gap-4 ir-border-t ir-pt-6 first:ir-border-t-0 first:ir-pt-0 lg:ir-grid-cols-[minmax(0,17rem)_minmax(0,1fr)] lg:ir-gap-10">
            <div>
                <h2 className="ir-text-[15px] ir-font-semibold ir-tracking-tight">{title}</h2>
                <div className="ir-mt-1 ir-text-[13px] ir-leading-relaxed ir-text-muted-foreground">{description}</div>
                {status !== undefined && <div className="ir-mt-3">{status}</div>}
            </div>
            <div className="ir-min-w-0 ir-rounded-xl ir-border ir-bg-card ir-p-5 ir-shadow-ir-xs">{children}</div>
        </section>
    );
}

/** The footer of a settings form: the save button + what happened. */
function SaveBar({ onSave, disabled, busy, saved, label = 'Guardar cambios' }: { onSave: () => void; disabled: boolean; busy: boolean; saved: boolean; label?: string }): ReactElement {
    return (
        <div className="ir-mt-1 ir-flex ir-items-center ir-gap-3 ir-border-t ir-pt-4">
            <Button onClick={onSave} disabled={disabled || busy}>
                {busy ? 'Guardando…' : label}
            </Button>
            {saved && <span className="ir-text-xs ir-text-success">Guardado.</span>}
        </div>
    );
}

function BillingTab(): ReactElement {
    const { data: settings } = usePlatformBillingSettings();
    const update = useUpdatePlatformBillingSettings();
    const [mp, setMp] = useState('');
    const [mpHook, setMpHook] = useState('');
    const [ppId, setPpId] = useState('');
    const [ppSecret, setPpSecret] = useState('');
    const [ppHook, setPpHook] = useState('');
    const [savedWhat, setSavedWhat] = useState<'mp' | 'pp' | null>(null);

    type BillingPayload = { mercadopago_access_token?: string; mercadopago_webhook_secret?: string; paypal_client_id?: string; paypal_secret?: string; paypal_webhook_id?: string; billing_sandbox?: boolean };
    const save = (payload: BillingPayload, what: 'mp' | 'pp' | null): void => {
        update.mutate(payload, {
            onSuccess: () => {
                setMp('');
                setMpHook('');
                setPpId('');
                setPpSecret('');
                setPpHook('');
                setSavedWhat(what);
            },
        });
    };

    // Only what was typed is sent: an empty secret field means "keep the current one".
    const saveMp = (): void => save({ ...(mp !== '' ? { mercadopago_access_token: mp } : {}), ...(mpHook !== '' ? { mercadopago_webhook_secret: mpHook } : {}) }, 'mp');
    const ppPairIncomplete = (ppId === '') !== (ppSecret === '');
    const savePp = (): void => save({ ...(ppId !== '' && ppSecret !== '' ? { paypal_client_id: ppId, paypal_secret: ppSecret } : {}), ...(ppHook !== '' ? { paypal_webhook_id: ppHook } : {}) }, 'pp');

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <SectionHeader tab="billing" />
            <div className="ir-flex ir-max-w-5xl ir-flex-col ir-gap-6">
                <SettingsRow
                    title="MercadoPago"
                    description="Cobros recurrentes en la moneda local de cada plan. El token decide el entorno: TEST-… cobra en pruebas, APP_USR-… cobra de verdad."
                    status={
                        <div className="ir-flex ir-flex-wrap ir-gap-2">
                            <ConfigChip ready={settings?.mercadopago_configured ?? false} />
                            <ConfigChip ready={settings?.mercadopago_webhook_configured ?? false} label="Firma de avisos" />
                        </div>
                    }
                >
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        <Field label="Access Token">
                            <Input type="password" autoComplete="off" value={mp} onChange={(e) => setMp(e.target.value)} placeholder={settings?.mercadopago_configured ? '•••••••• (déjalo vacío para conservarlo)' : 'APP_USR-…'} />
                        </Field>
                        <Field
                            label="Clave secreta del webhook (recomendada)"
                            hint="Sale en MercadoPago al configurar el webhook (pasos abajo). Sirve para comprobar que cada aviso viene de verdad de MercadoPago."
                        >
                            <Input type="password" autoComplete="off" value={mpHook} onChange={(e) => setMpHook(e.target.value)} placeholder={settings?.mercadopago_webhook_configured ? '••••••••' : 'clave del webhook'} />
                        </Field>
                        <SaveBar onSave={saveMp} disabled={mp === '' && mpHook === ''} busy={update.isPending} saved={savedWhat === 'mp'} />
                        {settings?.mercadopago_webhook_url !== undefined && (
                            <MercadoPagoWebhookGuide url={settings.mercadopago_webhook_url} open={(settings.mercadopago_configured ?? false) && !(settings.mercadopago_webhook_configured ?? false)} />
                        )}
                    </div>
                </SettingsRow>

                <SettingsRow
                    title="PayPal"
                    description="Suscripciones recurrentes en USD. Usa el Client ID y el Secret de tu app REST de PayPal."
                    status={
                        <div className="ir-flex ir-flex-wrap ir-gap-2">
                            <ConfigChip ready={settings?.paypal_configured ?? false} />
                            <ConfigChip ready={settings?.paypal_webhook_configured ?? false} label="Webhook" />
                        </div>
                    }
                >
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                            <Field label="Client ID">
                                <Input type="password" autoComplete="off" value={ppId} onChange={(e) => setPpId(e.target.value)} placeholder={settings?.paypal_configured ? '••••••••' : 'AY…'} />
                            </Field>
                            <Field label="Secret">
                                <Input type="password" autoComplete="off" value={ppSecret} onChange={(e) => setPpSecret(e.target.value)} placeholder={settings?.paypal_configured ? '••••••••' : 'EL…'} />
                            </Field>
                        </div>
                        {ppPairIncomplete && <p className="ir-text-xs ir-text-warning">El Client ID y el Secret se guardan juntos: completa los dos.</p>}
                        <Field label="Webhook ID" hint="Sale en PayPal al crear el webhook (pasos abajo). Obligatorio: sin él, PayPal no puede avisar de renovaciones ni cancelaciones.">
                            <Input type="password" autoComplete="off" value={ppHook} onChange={(e) => setPpHook(e.target.value)} placeholder={settings?.paypal_webhook_configured ? '••••••••' : 'WH-…'} />
                        </Field>
                        <label className="ir-flex ir-items-start ir-gap-2.5 ir-rounded-lg ir-bg-muted/50 ir-p-3 ir-text-sm">
                            <input type="checkbox" className="ir-mt-0.5" checked={settings?.billing_sandbox ?? true} onChange={(e) => save({ billing_sandbox: e.target.checked }, null)} />
                            <span>
                                <span className="ir-font-medium">Modo pruebas (sandbox)</span>
                                <span className="ir-block ir-text-xs ir-text-muted-foreground">Desactívalo solo con credenciales de producción. Se guarda al marcarlo.</span>
                            </span>
                        </label>
                        <SaveBar onSave={savePp} disabled={ppPairIncomplete || (ppId === '' && ppHook === '')} busy={update.isPending} saved={savedWhat === 'pp'} />
                        {settings?.paypal_webhook_url !== undefined && <PayPalWebhookGuide url={settings.paypal_webhook_url} />}
                    </div>
                </SettingsRow>
            </div>
        </div>
    );
}

/* ------------------------------- Integrations ------------------------------ */

function IntegrationsTab(): ReactElement {
    const { data: settings } = usePlatformIntegrations();
    const update = useUpdatePlatformIntegrations();
    const [gId, setGId] = useState('');
    const [gSecret, setGSecret] = useState('');
    const [gDev, setGDev] = useState('');
    const [gMcc, setGMcc] = useState('');
    const [mId, setMId] = useState('');
    const [mSecret, setMSecret] = useState('');
    const [savedWhat, setSavedWhat] = useState<'google' | 'meta' | null>(null);

    const save = (payload: Record<string, string>, what: 'google' | 'meta'): void => {
        update.mutate(payload, {
            onSuccess: () => {
                setGId('');
                setGSecret('');
                setGDev('');
                setGMcc('');
                setMId('');
                setMSecret('');
                setSavedWhat(what);
            },
        });
    };

    // Only the fields that were typed: empty means "keep what's saved".
    const pick = (fields: Record<string, string>): Record<string, string> => Object.fromEntries(Object.entries(fields).filter(([, value]) => value.trim() !== ''));
    const googlePayload = pick({ google_oauth_client_id: gId, google_oauth_client_secret: gSecret, google_ads_developer_token: gDev, google_ads_login_customer_id: gMcc });
    const metaPayload = pick({ meta_oauth_app_id: mId, meta_oauth_app_secret: mSecret });

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <SectionHeader tab="integrations" />
            <p className="ir-max-w-5xl ir-text-[13px] ir-leading-relaxed ir-text-muted-foreground">
                Las apps OAuth de la conexión de un clic. Se guardan cifradas y tienen prioridad sobre el <code className="ir-rounded ir-bg-muted ir-px-1">.env</code>; el botón «Conectar con…»
                aparece en cuanto quedan listas. Cada una trae su guía con las URLs de esta instalación para copiar.
            </p>
            <div className="ir-flex ir-max-w-5xl ir-flex-col ir-gap-6">
                <SettingsRow
                    title="Google"
                    description="Una sola app de Google Cloud cubre Analytics 4, Search Console y Google Ads."
                    status={
                        <div className="ir-flex ir-flex-wrap ir-gap-2">
                            <ConfigChip ready={settings?.google_connect_ready ?? false} note={settings?.google_from_env === true ? '(por .env)' : undefined} />
                            <ConfigChip ready={settings?.google_ads_developer_token_set ?? false} label="Google Ads" />
                        </div>
                    }
                >
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                            <Field label="OAuth Client ID">
                                <Input autoComplete="off" value={gId} onChange={(e) => setGId(e.target.value)} placeholder={settings?.google_oauth_client_id || '…apps.googleusercontent.com'} />
                            </Field>
                            <Field label="OAuth Client Secret">
                                <Input type="password" autoComplete="off" value={gSecret} onChange={(e) => setGSecret(e.target.value)} placeholder={settings?.google_oauth_client_secret_set ? '•••••••• (déjalo vacío para conservarlo)' : 'GOCSPX-…'} />
                            </Field>
                        </div>
                        {settings !== undefined && <GoogleSetupGuide setup={settings.setup} />}
                        <div className="ir-flex ir-flex-col ir-gap-4 ir-border-t ir-pt-4">
                            <p className="ir-text-xs ir-font-medium ir-text-muted-foreground">Solo para Google Ads</p>
                            <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                                <Field label="Developer token">
                                    <Input type="password" autoComplete="off" value={gDev} onChange={(e) => setGDev(e.target.value)} placeholder={settings?.google_ads_developer_token_set ? '••••••••' : 'del API Center'} />
                                </Field>
                                <Field label="Login Customer ID (MCC)" hint="Opcional: solo con cuenta administradora. 10 dígitos, sin guiones.">
                                    <Input autoComplete="off" value={gMcc} onChange={(e) => setGMcc(e.target.value)} placeholder={settings?.google_ads_login_customer_id || 'vacío si no aplica'} />
                                </Field>
                            </div>
                            <GoogleAdsSetupGuide />
                        </div>
                        <SaveBar onSave={() => save(googlePayload, 'google')} disabled={Object.keys(googlePayload).length === 0} busy={update.isPending} saved={savedWhat === 'google'} />
                    </div>
                </SettingsRow>

                <SettingsRow
                    title="Meta"
                    description="Una app de Meta cubre Facebook Ads e Instagram."
                    status={<ConfigChip ready={settings?.meta_connect_ready ?? false} note={settings?.meta_from_env === true ? '(por .env)' : undefined} />}
                >
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                            <Field label="App ID">
                                <Input autoComplete="off" value={mId} onChange={(e) => setMId(e.target.value)} placeholder={settings?.meta_oauth_app_id || 'App ID de Meta'} />
                            </Field>
                            <Field label="App Secret">
                                <Input type="password" autoComplete="off" value={mSecret} onChange={(e) => setMSecret(e.target.value)} placeholder={settings?.meta_oauth_app_secret_set ? '•••••••• (déjalo vacío para conservarlo)' : 'App Secret'} />
                            </Field>
                        </div>
                        {settings !== undefined && <MetaSetupGuide setup={settings.setup} />}
                        <SaveBar onSave={() => save(metaPayload, 'meta')} disabled={Object.keys(metaPayload).length === 0} busy={update.isPending} saved={savedWhat === 'meta'} />
                    </div>
                </SettingsRow>

                <AiPlatformRow />

                <MailSettingsCard />
            </div>
        </div>
    );
}

/* ------------------------------- Claude (IA) ------------------------------- */

function AiPlatformRow(): ReactElement {
    const { data: settings } = usePlatformIntegrations();
    const update = useUpdatePlatformIntegrations();
    const test = useTestPlatformAi();
    const [key, setKey] = useState('');
    const [saved, setSaved] = useState(false);

    const models = settings?.ai_models ?? [];
    const current = settings?.ai_model ?? '';
    // A model set in .env that the panel doesn't list still shows as the current choice.
    const listed = models.some((model) => model.id === current);
    const hint = models.find((model) => model.id === current)?.hint;
    const effort = settings?.ai_effort ?? 'medium';
    const effortHint = settings?.ai_efforts.find((option) => option.id === effort)?.hint;

    return (
        <SettingsRow
            title="Inteligencia artificial (Claude)"
            description="La IA de las agencias que no ponen su propia clave: resúmenes de los informes, «Generar con IA», respuestas a clientes y propuestas. Una agencia con su clave paga su consumo y elige su modelo."
            status={<ConfigChip ready={settings?.ai_ready ?? false} note={settings?.ai_from_env === true ? '(por .env)' : undefined} />}
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                <Field label="Clave de Anthropic" hint="Créala en console.anthropic.com → API Keys. Se guarda cifrada.">
                    <Input
                        type="password"
                        autoComplete="off"
                        value={key}
                        onChange={(e) => setKey(e.target.value)}
                        placeholder={settings?.anthropic_key_set ? '•••••••• (déjalo vacío para conservarla)' : 'sk-ant-…'}
                    />
                </Field>
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                    <Field label="Modelo por defecto" hint={hint ?? 'El que usan las agencias sin clave propia.'}>
                        <Select
                            value={current}
                            disabled={update.isPending || settings === undefined}
                            onChange={(e) => update.mutate({ ai_model: e.target.value }, { onSuccess: () => setSaved(true) })}
                        >
                            {!listed && current !== '' && <option value={current}>{current} (del .env)</option>}
                            {models.map((model) => (
                                <option key={model.id} value={model.id}>
                                    {model.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field
                        label="Nivel de esfuerzo"
                        hint={settings?.ai_effort_applies === false ? 'Se aplica a los modelos de la lista; el del .env usa su nivel de siempre.' : (effortHint ?? 'Cuánto piensa la IA antes de escribir.')}
                    >
                        <Select
                            value={effort}
                            disabled={update.isPending || settings === undefined}
                            onChange={(e) => update.mutate({ ai_effort: e.target.value }, { onSuccess: () => setSaved(true) })}
                        >
                            {(settings?.ai_efforts ?? []).map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                </div>
                <SaveBar
                    onSave={() => update.mutate({ anthropic_key: key.trim() }, { onSuccess: () => { setKey(''); setSaved(true); } })}
                    disabled={key.trim() === ''}
                    busy={update.isPending}
                    saved={saved}
                    label="Guardar clave"
                />
                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3 ir-border-t ir-pt-4">
                    <Button variant="ghost" onClick={() => test.mutate()} disabled={test.isPending || settings?.ai_ready !== true}>
                        {test.isPending ? 'Probando…' : 'Probar la IA'}
                    </Button>
                    {test.data !== undefined && <span className={`ir-text-xs ${test.data.ok ? 'ir-text-success' : 'ir-text-danger'}`}>{test.data.message}</span>}
                    {test.data === undefined && <span className="ir-text-xs ir-text-muted-foreground">Envía un mensaje corto con la clave y el modelo guardados.</span>}
                </div>
            </div>
        </SettingsRow>
    );
}

/* ------------------------------ Outbound mail ------------------------------ */

function MailSettingsCard(): ReactElement {
    const { data: settings } = usePlatformMailSettings();
    const update = useUpdatePlatformMailSettings();
    const sendTest = useTestPlatformMail();
    const [host, setHost] = useState('');
    const [port, setPort] = useState('');
    const [user, setUser] = useState('');
    const [pass, setPass] = useState('');
    const [scheme, setScheme] = useState('');
    const [fromAddr, setFromAddr] = useState('');
    const [fromName, setFromName] = useState('');
    const [testTo, setTestTo] = useState('');

    // Prefill the non-secret fields from what's saved, once, when the settings arrive.
    const [seeded, setSeeded] = useState(false);
    useEffect(() => {
        if (settings && !seeded) {
            setSeeded(true);
            setHost(settings.mail_host);
            setPort(settings.mail_port);
            setUser(settings.mail_username);
            setScheme(settings.mail_scheme);
            setFromAddr(settings.mail_from_address);
            setFromName(settings.mail_from_name);
        }
    }, [settings, seeded]);

    const saveSmtp = (): void => {
        update.mutate({
            mail_mailer: 'smtp',
            mail_host: host,
            mail_port: port,
            mail_username: user,
            mail_scheme: scheme,
            mail_from_address: fromAddr,
            mail_from_name: fromName,
            ...(pass !== '' ? { mail_password: pass } : {}),
        }, { onSuccess: () => setPass('') });
    };

    const sends = settings?.mail_sends ?? false;

    return (
        <SettingsRow
            title="Correo saliente"
            description="El servidor SMTP con el que salen los reportes y avisos. Se guarda cifrado y sustituye al .env."
            status={<ConfigChip ready={sends} note={sends ? `(${settings?.mail_mailer ?? ''})` : undefined} />}
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                {!sends && <p className="ir-rounded-lg ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-xs ir-text-warning">Los correos no salen: solo se registran en el log hasta que configures un servidor.</p>}
                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                    <Field label="Servidor SMTP (host)">
                        <Input autoComplete="off" value={host} onChange={(e) => setHost(e.target.value)} placeholder="smtp.tuservidor.com" />
                    </Field>
                    <Field label="Puerto">
                        <Input autoComplete="off" value={port} onChange={(e) => setPort(e.target.value)} placeholder="587" />
                    </Field>
                    <Field label="Usuario">
                        <Input autoComplete="off" value={user} onChange={(e) => setUser(e.target.value)} placeholder="usuario@imaginawp.com" />
                    </Field>
                    <Field label="Contraseña">
                        <Input type="password" autoComplete="off" value={pass} onChange={(e) => setPass(e.target.value)} placeholder={settings?.mail_password_set ? '•••••••• (deja en blanco para conservar)' : ''} />
                    </Field>
                    <Field label="Cifrado" hint="«Automático» detecta según el puerto (587→STARTTLS, 465→SSL). Elige uno explícito solo si tu servidor lo exige.">
                        <Select value={scheme} onChange={(e) => setScheme(e.target.value)}>
                            <option value="">Automático (según el puerto)</option>
                            <option value="smtp">STARTTLS — puerto 587</option>
                            <option value="smtps">SSL/TLS — puerto 465</option>
                        </Select>
                    </Field>
                </div>
                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                    <Field label="Remitente (email)">
                        <Input autoComplete="off" value={fromAddr} onChange={(e) => setFromAddr(e.target.value)} placeholder="reportes@imaginawp.com" />
                    </Field>
                    <Field label="Remitente (nombre)">
                        <Input autoComplete="off" value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder="Imagina Reports" />
                    </Field>
                </div>
                <SaveBar onSave={saveSmtp} disabled={false} busy={update.isPending} saved={update.isSuccess} />

                <div className="ir-border-t ir-pt-4">
                    <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">Envía un correo de prueba para confirmar que funciona (guarda primero).</p>
                    <div className="ir-flex ir-gap-2">
                        <Input autoComplete="off" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="tu@correo.com" />
                        <Button variant="ghost" onClick={() => sendTest.mutate(testTo)} disabled={sendTest.isPending || testTo === ''}>
                            {sendTest.isPending ? 'Enviando…' : 'Enviar prueba'}
                        </Button>
                    </div>
                    {sendTest.isSuccess && sendTest.data?.sent && <p className="ir-mt-2 ir-text-xs ir-text-success">Enviado ✓ Revisa la bandeja (y spam).</p>}
                    {sendTest.data?.sent === false && (
                        <div className="ir-mt-2 ir-rounded ir-bg-danger/10 ir-p-2 ir-text-xs ir-text-danger">
                            <p className="ir-font-medium">No se pudo enviar. Error del servidor:</p>
                            <p className="ir-mt-1 ir-break-words ir-font-mono ir-text-[11px]">{sendTest.data.error}</p>
                        </div>
                    )}
                    {sendTest.isError && <p className="ir-mt-2 ir-text-xs ir-text-danger">No se pudo contactar el servidor. Inténtalo de nuevo.</p>}
                </div>
            </div>
        </SettingsRow>
    );
}

/**
 * Agencies' own domains. One with DNS ready but no HTTPS needs the operator: add it to the
 * application in ServerAvatar (domain alias) and issue its certificate — it then goes live by
 * itself within 15 minutes (the domains:check schedule).
 */
function AgencyDomainsCard(): ReactElement {
    const { data: agencies = [] } = usePlatformAgencies();
    const withDomain = agencies.filter((agency) => agency.domain != null && agency.domain !== '');
    const waiting = withDomain.filter((agency) => agency.domain_status === 'pending_ssl');
    const [copied, setCopied] = useState(false);
    const STATUS: Record<string, { label: string; tone: 'success' | 'warning' | 'neutral' }> = {
        active: { label: 'Activo', tone: 'success' },
        pending_ssl: { label: 'Falta el certificado en el servidor', tone: 'warning' },
        pending_dns: { label: 'Esperando el DNS de la agencia', tone: 'neutral' },
    };

    return (
        <Card
            className="ir-max-w-5xl"
            title="Dominios de las agencias"
            description="Cuando el DNS de una agencia ya apunta aquí, añade su dominio como alias de la aplicación en ServerAvatar y emite el certificado SSL. Se activa solo en menos de 15 minutos."
            actions={
                waiting.length > 0 ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => void navigator.clipboard?.writeText(waiting.map((agency) => agency.domain).join('\n')).then(() => { setCopied(true); window.setTimeout(() => setCopied(false), 1500); })}
                    >
                        {copied ? <Check className="ir-size-3.5" /> : null}
                        {copied ? 'Copiados' : `Copiar ${waiting.length} por activar`}
                    </Button>
                ) : undefined
            }
        >
            {withDomain.length === 0 ? (
                <p className="ir-text-sm ir-text-muted-foreground">Ninguna agencia ha conectado su dominio todavía.</p>
            ) : (
                <div className="ir-flex ir-flex-col ir-divide-y">
                    {withDomain.map((agency) => {
                        const status = STATUS[agency.domain_status ?? 'pending_dns'] ?? STATUS.pending_dns;

                        return (
                            <div key={agency.id} className="ir-flex ir-flex-wrap ir-items-center ir-gap-3 ir-py-2.5">
                                <div className="ir-min-w-0 ir-flex-1">
                                    <p className="ir-truncate ir-font-mono ir-text-sm">{agency.domain}</p>
                                    <p className="ir-truncate ir-text-xs ir-text-muted-foreground">{agency.name}</p>
                                </div>
                                <span className="ir-inline-flex ir-items-center ir-gap-1.5 ir-text-xs ir-font-medium">
                                    <StatusDot tone={status?.tone ?? 'neutral'} />
                                    {status?.label}
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
        </Card>
    );
}

/* ---------------------------------- Screen --------------------------------- */

/**
 * What each section is for, in one line.
 *
 * Every tab used to open straight into its widgets under a single generic "Plataforma"
 * heading, so nothing on screen said where you were or what the section was for. One
 * header component used by all six is most of what makes them read as one product.
 */
const SECTIONS: Record<PlatformTab, { title: string; description: string; icon: typeof Building2 }> = {
    overview: {
        title: 'Resumen',
        description: 'El estado de toda la instalación de un vistazo: agencias, uso y facturación.',
        icon: Gauge,
    },
    agencies: {
        title: 'Agencias',
        description: 'Alta, plan, suspensión y borrado de cada agencia, y de las personas que la usan.',
        icon: Building2,
    },
    plans: {
        title: 'Planes',
        description: 'Los límites y las funciones que cada agencia puede usar según lo que paga.',
        icon: LayoutGrid,
    },
    billing: {
        title: 'Cobros',
        description: 'Pasarelas con las que las agencias pagan su plan. Las credenciales se guardan cifradas.',
        icon: CreditCard,
    },
    integrations: {
        title: 'Integraciones',
        description: 'Credenciales de las APIs que comparten todas las agencias de esta instalación.',
        icon: Plug,
    },
    system: {
        title: 'Sistema',
        description: 'Versión instalada, actualizaciones y vuelta atrás.',
        icon: DownloadCloud,
    },
};

function SectionHeader({ tab, action }: { tab: PlatformTab; action?: ReactNode }): ReactElement {
    const section = SECTIONS[tab];

    return <PageHeader className="ir-mb-0" title={section.title} description={section.description} actions={action} />;
}

export function PlatformScreen({
    tab,
    onTab,
}: {
    tab: PlatformTab;
    onTab: (tab: PlatformTab) => void;
}): ReactElement {
    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            {tab === 'overview' && <OverviewTab onOpenAgencies={() => onTab('agencies')} />}
            {tab === 'agencies' && <AgenciesTab />}
            {tab === 'plans' && <PlansTab />}
            {tab === 'billing' && <BillingTab />}
            {tab === 'integrations' && <IntegrationsTab />}
            {tab === 'system' && (
                <>
                    <SectionHeader tab="system" />
                    <SystemUpdatePanel />
                    <AgencyDomainsCard />
                </>
            )}
        </div>
    );
}
