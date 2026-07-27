import {
    Activity,
    AlertTriangle,
    Building2,
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
    useUpdatePlatformMailSettings,
} from '../api';
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
import { Badge, Button, Card, Field, Input, Modal, Select } from '../components/ui';
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

/** A single headline number. The overview is built entirely from these. */
function Stat({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'neutral',
}: {
    label: string;
    value: string | number;
    hint?: string;
    icon: typeof Building2;
    tone?: 'neutral' | 'danger' | 'success';
}): ReactElement {
    const toneClass = tone === 'danger' ? 'ir-text-danger' : tone === 'success' ? 'ir-text-success' : 'ir-text-foreground';

    return (
        <div className="ir-rounded-lg ir-border ir-bg-card ir-p-4 ir-shadow-ir-xs">
            <div className="ir-flex ir-items-center ir-gap-2 ir-text-xs ir-font-medium ir-text-muted-foreground">
                <Icon className="ir-size-3.5" />
                {label}
            </div>
            <p className={`ir-mt-2 ir-text-2xl ir-font-semibold ir-tabular-nums ir-tracking-tight ${toneClass}`}>{value}</p>
            {hint !== undefined && <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">{hint}</p>}
        </div>
    );
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
            <h3 className="ir-text-xs ir-font-semibold ir-uppercase ir-tracking-wide ir-text-muted-foreground">{title}</h3>
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
            <h2 className="ir-text-xs ir-font-semibold ir-uppercase ir-tracking-wide ir-text-muted-foreground">{title}</h2>
            {action}
        </div>
    );
}

function OverviewTab({ onOpenAgencies }: { onOpenAgencies: () => void }): ReactElement {
    const { data, isLoading } = usePlatformOverview();

    if (isLoading || data === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando el estado de la plataforma…</p>;
    }

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <div className="ir-flex ir-flex-col ir-gap-2.5">
                <GroupHeading
                    title="Agencias y personas"
                    action={
                        <Button variant="ghost" size="sm" onClick={onOpenAgencies}>
                            Gestionar agencias
                        </Button>
                    }
                />
                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-4">
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
                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-4">
                    <Stat icon={Users} label="Clientes" value={numberFmt.format(data.workload.clients)} />
                    <Stat icon={Gauge} label="Sitios" value={numberFmt.format(data.workload.sites)} />
                    <Stat icon={Plug} label="Fuentes de datos" value={numberFmt.format(data.workload.data_sources)} />
                    <Stat icon={LayoutGrid} label="Reportes este mes" value={numberFmt.format(data.workload.reports_this_month)} />
                </div>
            </div>

            <div className="ir-flex ir-flex-col ir-gap-2.5">
                <GroupHeading title="Almacenamiento y cobros" />
                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-4">
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
    const [addingUser, setAddingUser] = useState(false);

    const suspended = agency?.status === 'suspended';

    return (
        <Modal onClose={onClose} className="ir-max-w-3xl">
            <Card
                title={agency?.name ?? 'Agencia'}
                description={agency !== undefined ? `/${agency.slug} · alta ${agency.created_at?.slice(0, 10) ?? '—'}` : undefined}
                actions={
                    <>
                        <Button variant="accent" size="sm" disabled={impersonate.isPending} onClick={() => impersonate.mutate(agencyId)}>
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
                <Button onClick={() => setCreating(true)}>
                    <Plus className="ir-size-4" />
                    Nueva agencia
                </Button>
            </div>

            {/* Plain surface rather than <Card>: the table owns its own edge-to-edge padding. */}
            <div className="ir-rounded-lg ir-border ir-bg-card ir-shadow-ir-sm">
                <div className="ir-overflow-x-auto">
                    <table className="ir-w-full ir-border-collapse ir-text-left ir-text-sm">
                        <thead>
                            <tr className="ir-border-b">
                                {['Agencia', 'Plan', 'Estado', 'Uso', ''].map((header, index) => (
                                    <th
                                        key={header === '' ? `col-${index}` : header}
                                        className="ir-whitespace-nowrap ir-px-3 ir-py-2.5 ir-text-xs ir-font-semibold ir-uppercase ir-tracking-wide ir-text-muted-foreground first:ir-pl-5 last:ir-pr-5"
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
                                        <p className="ir-font-medium">{agency.name}</p>
                                        <p className="ir-text-xs ir-text-muted-foreground">/{agency.slug}</p>
                                    </td>
                                    <td className="ir-px-3 ir-py-3">
                                        {agency.plan !== null ? <Badge tone="accent">{agency.plan.name}</Badge> : <span className="ir-text-xs ir-text-muted-foreground">Sin plan</span>}
                                    </td>
                                    <td className="ir-px-3 ir-py-3">
                                        <Badge tone={agency.status === 'suspended' ? 'danger' : 'success'}>{agency.status === 'suspended' ? 'Suspendida' : 'Activa'}</Badge>
                                    </td>
                                    <td className="ir-w-72 ir-px-3 ir-py-3">
                                        <div className="ir-grid ir-grid-cols-2 ir-gap-x-4 ir-gap-y-1.5">
                                            <UsageBar label="Sitios" used={agency.usage.sites} limit={agency.limits.max_sites} />
                                            <UsageBar label="Reportes/mes" used={agency.usage.reports_this_month} limit={agency.limits.max_reports_per_month} />
                                        </div>
                                    </td>
                                    <td className="ir-px-3 ir-py-3 last:ir-pr-5">
                                        <Button variant="ghost" size="sm" onClick={() => setOpenId(agency.id)}>
                                            <Pencil className="ir-size-3.5" />
                                            Gestionar
                                        </Button>
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
    const remove = useDeletePlan();
    const [editing, setEditing] = useState<Plan | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <div className="ir-flex ir-items-center ir-justify-between ir-gap-3">
                <p className="ir-text-sm ir-text-muted-foreground">Los planes definen los límites y las funciones que cada agencia puede usar.</p>
                <Button onClick={() => setCreating(true)}>
                    <Plus className="ir-size-4" />
                    Nuevo plan
                </Button>
            </div>
            {isLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
            ) : plans.length === 0 ? (
                <Card>
                    <p className="ir-text-sm ir-text-muted-foreground">Aún no hay planes. Crea el primero para poder asignarlo a las agencias.</p>
                </Card>
            ) : (
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2 lg:ir-grid-cols-3">
                    {plans.map((plan) => (
                        <Card
                            key={plan.id}
                            title={plan.name}
                            description={plan.monthly_price != null ? `${plan.monthly_price} ${plan.currency}/mes` : 'Sin precio'}
                            actions={
                                <>
                                    <Button variant="ghost" size="sm" title="Editar" onClick={() => setEditing(plan)}>
                                        <Pencil className="ir-size-3.5" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Eliminar"
                                        onClick={() => {
                                            if (window.confirm(`¿Eliminar el plan «${plan.name}»? Las agencias que lo usan quedarán sin plan.`)) remove.mutate(plan.id);
                                        }}
                                    >
                                        <Trash2 className="ir-size-3.5 ir-text-danger" />
                                    </Button>
                                </>
                            }
                        >
                            <dl className="ir-flex ir-flex-col ir-gap-1 ir-text-xs">
                                {NUM_FIELDS.map((f) => (
                                    <div key={f.key} className="ir-flex ir-justify-between ir-gap-3">
                                        <dt className="ir-text-muted-foreground">{f.label}</dt>
                                        <dd className="ir-tabular-nums ir-font-medium">{plan[f.key] ?? '∞'}</dd>
                                    </div>
                                ))}
                            </dl>
                            <div className="ir-mt-3 ir-flex ir-flex-wrap ir-gap-1">
                                {FEATURES.filter((f) => plan.features?.[f.key]).map((f) => (
                                    <Badge key={f.key} tone="accent">{f.label}</Badge>
                                ))}
                            </div>
                        </Card>
                    ))}
                </div>
            )}
            {creating && <PlanModal plan={null} onClose={() => setCreating(false)} />}
            {editing !== null && <PlanModal plan={editing} onClose={() => setEditing(null)} />}
        </div>
    );
}

/* --------------------------------- Billing --------------------------------- */

/** «configurado ✓» / «sin configurar», the same chip everywhere in this panel. */
function ConfigChip({ ready, note }: { ready: boolean; note?: string }): ReactElement {
    return ready ? <Badge tone="success">configurado ✓{note !== undefined ? ` ${note}` : ''}</Badge> : <Badge tone="warning">sin configurar</Badge>;
}

function BillingTab(): ReactElement {
    const { data: settings } = usePlatformBillingSettings();
    const update = useUpdatePlatformBillingSettings();
    const [mp, setMp] = useState('');
    const [mpHook, setMpHook] = useState('');
    const [ppId, setPpId] = useState('');
    const [ppSecret, setPpSecret] = useState('');
    const [ppHook, setPpHook] = useState('');

    const save = (payload: { mercadopago_access_token?: string; mercadopago_webhook_secret?: string; paypal_client_id?: string; paypal_secret?: string; paypal_webhook_id?: string; billing_sandbox?: boolean }): void => {
        update.mutate(payload, { onSuccess: () => { setMp(''); setMpHook(''); setPpId(''); setPpSecret(''); setPpHook(''); } });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <Card
                title="MercadoPago"
                description="Cobros recurrentes en la moneda local de cada plan. Pega tu Access Token (se guarda cifrado)."
                actions={<ConfigChip ready={settings?.mercadopago_configured ?? false} />}
            >
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Access Token" hint="El token decide el entorno: uno TEST-… cobra en pruebas, uno APP_USR-… cobra de verdad. La casilla «sandbox» de abajo no afecta a MercadoPago.">
                        <Input type="password" autoComplete="off" value={mp} onChange={(e) => setMp(e.target.value)} placeholder={settings?.mercadopago_configured ? '•••••••• (deja en blanco para conservar)' : 'APP_USR-…'} />
                    </Field>
                    <Button className="ir-self-start" onClick={() => save({ mercadopago_access_token: mp })} disabled={update.isPending || mp === ''}>
                        Guardar
                    </Button>
                    <div className="ir-mt-1 ir-border-t ir-pt-3">
                        <div className="ir-mb-2 ir-flex ir-items-center ir-gap-2">
                            <span className="ir-text-xs ir-text-muted-foreground">Clave secreta del webhook</span>
                            <ConfigChip ready={settings?.mercadopago_webhook_configured ?? false} />
                        </div>
                        <Field
                            label="Clave secreta (opcional)"
                            hint="En MercadoPago → Tus integraciones → Webhooks. Con ella se valida la firma de cada aviso. Sin ella el cobro sigue siendo seguro (el estado siempre se consulta a MercadoPago), pero se acepta tráfico sin firmar."
                        >
                            <div className="ir-flex ir-gap-2">
                                <Input type="password" autoComplete="off" value={mpHook} onChange={(e) => setMpHook(e.target.value)} placeholder={settings?.mercadopago_webhook_configured ? '••••••••' : 'clave del webhook'} />
                                <Button variant="ghost" onClick={() => save({ mercadopago_webhook_secret: mpHook })} disabled={update.isPending || mpHook === ''}>
                                    Guardar
                                </Button>
                            </div>
                        </Field>
                    </div>
                </div>
            </Card>

            <Card
                title="PayPal"
                description="Suscripciones recurrentes. Client ID + Secret de tu app REST de PayPal."
                actions={<ConfigChip ready={settings?.paypal_configured ?? false} />}
            >
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="Client ID">
                            <Input type="password" autoComplete="off" value={ppId} onChange={(e) => setPpId(e.target.value)} placeholder={settings?.paypal_configured ? '••••••••' : 'AY…'} />
                        </Field>
                        <Field label="Secret">
                            <Input type="password" autoComplete="off" value={ppSecret} onChange={(e) => setPpSecret(e.target.value)} placeholder={settings?.paypal_configured ? '••••••••' : 'EL…'} />
                        </Field>
                    </div>
                    <Button className="ir-self-start" onClick={() => save({ paypal_client_id: ppId, paypal_secret: ppSecret })} disabled={update.isPending || ppId === '' || ppSecret === ''}>
                        Guardar
                    </Button>
                    <div className="ir-mt-1 ir-border-t ir-pt-3">
                        <div className="ir-mb-2 ir-flex ir-items-center ir-gap-2">
                            <span className="ir-text-xs ir-text-muted-foreground">Webhook ID</span>
                            <ConfigChip ready={settings?.paypal_webhook_configured ?? false} />
                        </div>
                        {settings?.paypal_webhook_configured === false && (
                            <p className="ir-mb-2 ir-text-xs ir-text-warning">Sin esto, los webhooks de PayPal se rechazan y las suscripciones no se actualizan.</p>
                        )}
                        <Field label="Webhook ID" hint="En tu app de PayPal → Webhooks. Se usa para verificar la firma de cada webhook (obligatorio).">
                            <div className="ir-flex ir-gap-2">
                                <Input type="password" autoComplete="off" value={ppHook} onChange={(e) => setPpHook(e.target.value)} placeholder={settings?.paypal_webhook_configured ? '••••••••' : 'WH-…'} />
                                <Button variant="ghost" onClick={() => save({ paypal_webhook_id: ppHook })} disabled={update.isPending || ppHook === ''}>
                                    Guardar
                                </Button>
                            </div>
                        </Field>
                    </div>
                </div>
            </Card>

            <Card title="Entorno de PayPal">
                <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm">
                    <input type="checkbox" checked={settings?.billing_sandbox ?? true} onChange={(e) => save({ billing_sandbox: e.target.checked })} />
                    Modo sandbox (pruebas). Desactívalo solo cuando uses credenciales de producción.
                </label>
                <p className="ir-mt-2 ir-text-xs ir-text-muted-foreground">
                    Solo afecta a PayPal. En MercadoPago el entorno lo decide el token que pegues arriba.
                </p>
                {update.isSuccess && <p className="ir-mt-2 ir-text-xs ir-text-success">Guardado.</p>}
            </Card>
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

    const save = (payload: Record<string, string>): void => {
        update.mutate(payload, {
            onSuccess: () => {
                setGSecret('');
                setGDev('');
                setMSecret('');
            },
        });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <p className="ir-text-sm ir-text-muted-foreground">
                Credenciales de las apps OAuth para la conexión de un clic. Se guardan cifradas y tienen prioridad sobre las
                variables del <code className="ir-rounded ir-bg-muted ir-px-1">.env</code>. El botón «Conectar con…» aparece en cuanto quedan «listo».
                Consulta <strong>docs/oauth-connect-setup.md</strong> para obtenerlas.
            </p>

            <Card
                title="Google (GA4, Search Console, Google Ads)"
                description="Una sola app OAuth de Google Cloud cubre las tres fuentes."
                actions={<ConfigChip ready={settings?.google_connect_ready ?? false} note={settings?.google_from_env === true ? '(por .env)' : undefined} />}
            >
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="OAuth Client ID">
                            <Input autoComplete="off" value={gId} onChange={(e) => setGId(e.target.value)} placeholder={settings?.google_oauth_client_id || '…apps.googleusercontent.com'} />
                        </Field>
                        <Field label="OAuth Client Secret">
                            <Input type="password" autoComplete="off" value={gSecret} onChange={(e) => setGSecret(e.target.value)} placeholder={settings?.google_oauth_client_secret_set ? '•••••••• (deja en blanco para conservar)' : 'GOCSPX-…'} />
                        </Field>
                    </div>
                    <Button className="ir-self-start" onClick={() => save({ google_oauth_client_id: gId, ...(gSecret !== '' ? { google_oauth_client_secret: gSecret } : {}) })} disabled={update.isPending || (gId === '' && gSecret === '')}>
                        Guardar Google
                    </Button>
                    <div className="ir-mt-1 ir-border-t ir-pt-3">
                        <div className="ir-mb-2 ir-flex ir-items-center ir-gap-2">
                            <span className="ir-text-xs ir-text-muted-foreground">Solo para <strong>Google Ads</strong>: developer token</span>
                            <ConfigChip ready={settings?.google_ads_developer_token_set ?? false} />
                        </div>
                        <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                            <Field label="Developer token">
                                <Input type="password" autoComplete="off" value={gDev} onChange={(e) => setGDev(e.target.value)} placeholder={settings?.google_ads_developer_token_set ? '••••••••' : 'del API Center'} />
                            </Field>
                            <Field label="Login Customer ID (MCC)" hint="Opcional, solo si usas una cuenta administradora. 10 dígitos, sin guiones.">
                                <Input autoComplete="off" value={gMcc} onChange={(e) => setGMcc(e.target.value)} placeholder={settings?.google_ads_login_customer_id || 'vacío si no aplica'} />
                            </Field>
                        </div>
                        <Button variant="ghost" className="ir-mt-2 ir-self-start" onClick={() => save({ google_ads_login_customer_id: gMcc, ...(gDev !== '' ? { google_ads_developer_token: gDev } : {}) })} disabled={update.isPending || (gDev === '' && gMcc === '')}>
                            Guardar Google Ads
                        </Button>
                    </div>
                </div>
            </Card>

            <Card
                title="Meta (Facebook / Instagram)"
                description="Una app de Meta cubre Facebook Ads e Instagram."
                actions={<ConfigChip ready={settings?.meta_connect_ready ?? false} note={settings?.meta_from_env === true ? '(por .env)' : undefined} />}
            >
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="App ID">
                            <Input autoComplete="off" value={mId} onChange={(e) => setMId(e.target.value)} placeholder={settings?.meta_oauth_app_id || 'App ID de Meta'} />
                        </Field>
                        <Field label="App Secret">
                            <Input type="password" autoComplete="off" value={mSecret} onChange={(e) => setMSecret(e.target.value)} placeholder={settings?.meta_oauth_app_secret_set ? '•••••••• (deja en blanco para conservar)' : 'App Secret'} />
                        </Field>
                    </div>
                    <Button className="ir-self-start" onClick={() => save({ meta_oauth_app_id: mId, ...(mSecret !== '' ? { meta_oauth_app_secret: mSecret } : {}) })} disabled={update.isPending || (mId === '' && mSecret === '')}>
                        Guardar Meta
                    </Button>
                </div>
            </Card>
            {update.isSuccess && <p className="ir-text-xs ir-text-success">Guardado.</p>}

            <MailSettingsCard />
        </div>
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
        <Card
            title="Correo saliente (SMTP)"
            description="Con qué servidor se envían los reportes por email. Se guarda cifrado; sustituye a las variables del .env."
            actions={sends ? <Badge tone="success">envía por «{settings?.mail_mailer}» ✓</Badge> : <Badge tone="warning">sin configurar</Badge>}
        >
            <div className="ir-flex ir-flex-col ir-gap-3">
                {!sends && <p className="ir-text-xs ir-text-warning">Los correos no salen: solo se registran en el log hasta que configures un servidor.</p>}
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
                <Button className="ir-self-start" onClick={saveSmtp} disabled={update.isPending}>
                    Guardar correo
                </Button>

                <div className="ir-mt-1 ir-border-t ir-pt-3">
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
        </Card>
    );
}

/* ---------------------------------- Screen --------------------------------- */

type Tab = 'overview' | 'agencies' | 'plans' | 'billing' | 'integrations' | 'system';

const TABS: { key: Tab; label: string; icon: typeof Building2 }[] = [
    { key: 'overview', label: 'Resumen', icon: Gauge },
    { key: 'agencies', label: 'Agencias', icon: Building2 },
    { key: 'plans', label: 'Planes', icon: LayoutGrid },
    { key: 'billing', label: 'Facturación', icon: CreditCard },
    { key: 'integrations', label: 'Integraciones', icon: Plug },
    { key: 'system', label: 'Sistema', icon: DownloadCloud },
];

export function PlatformScreen(): ReactElement {
    const [tab, setTab] = useState<Tab>('overview');

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            <div>
                <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Plataforma</h1>
                <p className="ir-mt-1 ir-text-sm ir-text-muted-foreground">
                    Control total de la instalación: agencias y sus usuarios, planes, cobros, integraciones y actualizaciones.
                </p>
            </div>
            <div className="ir-flex ir-flex-wrap ir-gap-1 ir-self-start ir-rounded-lg ir-bg-muted ir-p-1">
                {TABS.map(({ key, label, icon: Icon }) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={`ir-inline-flex ir-items-center ir-gap-1.5 ir-rounded-md ir-px-3 ir-py-1.5 ir-text-sm ir-font-medium ir-transition-colors ${tab === key ? 'ir-bg-card ir-text-foreground ir-shadow-ir-xs' : 'ir-text-muted-foreground hover:ir-text-foreground'}`}
                    >
                        <Icon className="ir-size-4" />
                        {label}
                    </button>
                ))}
            </div>
            {tab === 'overview' && <OverviewTab onOpenAgencies={() => setTab('agencies')} />}
            {tab === 'agencies' && <AgenciesTab />}
            {tab === 'plans' && <PlansTab />}
            {tab === 'billing' && <BillingTab />}
            {tab === 'integrations' && <IntegrationsTab />}
            {tab === 'system' && <SystemUpdatePanel />}
        </div>
    );
}
