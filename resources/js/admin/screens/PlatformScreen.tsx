import { Building2, DownloadCloud, LayoutGrid, LogIn, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { SystemUpdatePanel } from '../components/SystemUpdatePanel';

import {
    type PlanInput,
    useCreatePlan,
    useCreatePlatformAgency,
    useDeletePlan,
    useImpersonateAgency,
    usePlatformAgencies,
    usePlatformPlans,
    useUpdatePlan,
    useUpdatePlatformAgency,
} from '../api';
import { Badge, Button, Card, Field, Input, Modal, Select } from '../components/ui';
import type { Plan, PlatformAgency } from '../types';

const FEATURES: { key: string; label: string }[] = [
    { key: 'ai_builder', label: 'Generador con IA' },
    { key: 'white_label', label: 'Marca blanca' },
    { key: 'remove_branding', label: 'Quitar «powered by»' },
    { key: 'custom_domain', label: 'Dominio propio' },
];

/** A used/limit progress bar (null limit = ilimitado). */
function UsageBar({ label, used, limit }: { label: string; used: number; limit: number | null }): ReactElement {
    const pct = limit === null || limit === 0 ? 0 : Math.min(100, (used / limit) * 100);
    const over = limit !== null && used >= limit;

    return (
        <div>
            <div className="ir-flex ir-justify-between ir-text-[11px] ir-text-muted-foreground">
                <span>{label}</span>
                <span className={over ? 'ir-font-medium ir-text-danger' : ''}>
                    {used}/{limit ?? '∞'}
                </span>
            </div>
            <div className="ir-mt-0.5 ir-h-1.5 ir-overflow-hidden ir-rounded ir-bg-muted">
                <div className={`ir-h-full ir-rounded ${over ? 'ir-bg-danger' : 'ir-bg-primary'}`} style={{ width: `${limit === null ? 6 : pct}%` }} />
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

function AgencyRow({ agency, plans }: { agency: PlatformAgency; plans: Plan[] }): ReactElement {
    const update = useUpdatePlatformAgency();
    const impersonate = useImpersonateAgency();
    const suspended = agency.status === 'suspended';

    return (
        <div className="ir-flex ir-flex-col ir-gap-3 ir-rounded-lg ir-border ir-bg-card ir-p-4">
            <div className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3">
                <div className="ir-min-w-0">
                    <p className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-font-semibold">
                        {agency.name}
                        {suspended && <Badge tone="danger">Suspendida</Badge>}
                    </p>
                    <p className="ir-text-xs ir-text-muted-foreground">/{agency.slug}</p>
                </div>
                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                    <Select className="ir-h-8 ir-w-40 ir-text-xs" value={agency.plan_id ?? ''} onChange={(e) => update.mutate({ id: agency.id, plan_id: e.target.value === '' ? null : Number(e.target.value) })}>
                        <option value="">Sin plan</option>
                        {plans.map((plan) => (
                            <option key={plan.id} value={plan.id}>
                                {plan.name}
                            </option>
                        ))}
                    </Select>
                    <Button variant="outline" size="sm" onClick={() => update.mutate({ id: agency.id, status: suspended ? 'active' : 'suspended' })} title={suspended ? 'Reactivar' : 'Suspender'}>
                        <Power className="ir-size-3.5" />
                        {suspended ? 'Reactivar' : 'Suspender'}
                    </Button>
                    <Button variant="accent" size="sm" onClick={() => impersonate.mutate(agency.id)} disabled={impersonate.isPending} title="Entrar a esta agencia">
                        <LogIn className="ir-size-3.5" />
                        Entrar
                    </Button>
                </div>
            </div>
            <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-4">
                <UsageBar label="Sitios" used={agency.usage.sites} limit={agency.limits.max_sites} />
                <UsageBar label="Fuentes" used={agency.usage.data_sources} limit={agency.limits.max_data_sources} />
                <UsageBar label="Clientes" used={agency.usage.clients} limit={agency.limits.max_clients} />
                <UsageBar label="Reportes/mes" used={agency.usage.reports_this_month} limit={agency.limits.max_reports_per_month} />
            </div>
        </div>
    );
}

function AgenciesTab(): ReactElement {
    const { data: agencies = [], isLoading } = usePlatformAgencies();
    const { data: plans = [] } = usePlatformPlans();
    const [creating, setCreating] = useState(false);

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <div className="ir-flex ir-justify-end">
                <Button onClick={() => setCreating(true)}>
                    <Plus className="ir-size-4" />
                    Nueva agencia
                </Button>
            </div>
            {isLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
            ) : agencies.length === 0 ? (
                <p className="ir-text-sm ir-text-muted-foreground">Aún no hay agencias. Crea la primera.</p>
            ) : (
                agencies.map((agency) => <AgencyRow key={agency.id} agency={agency} plans={plans} />)
            )}
            {creating && <CreateAgencyModal plans={plans} onClose={() => setCreating(false)} />}
        </div>
    );
}

/* ----------------------------------- Plans --------------------------------- */

type NumericPlanKey = 'max_sites' | 'max_data_sources' | 'max_clients' | 'max_users' | 'max_reports_per_month';

const NUM_FIELDS: { key: NumericPlanKey; label: string }[] = [
    { key: 'max_sites', label: 'Sitios' },
    { key: 'max_data_sources', label: 'Fuentes' },
    { key: 'max_clients', label: 'Clientes' },
    { key: 'max_users', label: 'Usuarios' },
    { key: 'max_reports_per_month', label: 'Reportes/mes' },
];

function PlanModal({ plan, onClose }: { plan: Plan | null; onClose: () => void }): ReactElement {
    const create = useCreatePlan();
    const update = useUpdatePlan();
    const [name, setName] = useState(plan?.name ?? '');
    const [price, setPrice] = useState(plan?.monthly_price != null ? String(plan.monthly_price) : '');
    const [limits, setLimits] = useState<Record<string, string>>(
        Object.fromEntries(NUM_FIELDS.map((f) => [f.key, plan?.[f.key] != null ? String(plan[f.key]) : ''])),
    );
    const [features, setFeatures] = useState<Record<string, boolean>>(
        Object.fromEntries(FEATURES.map((f) => [f.key, plan?.features?.[f.key] ?? false])),
    );

    const numOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value));

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        const payload: PlanInput = {
            name: name.trim(),
            monthly_price: numOrNull(price),
            max_sites: numOrNull(limits.max_sites ?? ''),
            max_data_sources: numOrNull(limits.max_data_sources ?? ''),
            max_clients: numOrNull(limits.max_clients ?? ''),
            max_users: numOrNull(limits.max_users ?? ''),
            max_reports_per_month: numOrNull(limits.max_reports_per_month ?? ''),
            features,
        };
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
                        <Field label="Precio mensual">
                            <Input type="number" min="0" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="49" />
                        </Field>
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
            <div className="ir-flex ir-justify-end">
                <Button onClick={() => setCreating(true)}>
                    <Plus className="ir-size-4" />
                    Nuevo plan
                </Button>
            </div>
            {isLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
            ) : (
                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-3">
                    {plans.map((plan) => (
                        <div key={plan.id} className="ir-flex ir-flex-col ir-gap-2 ir-rounded-lg ir-border ir-bg-card ir-p-4">
                            <div className="ir-flex ir-items-start ir-justify-between">
                                <div>
                                    <p className="ir-text-sm ir-font-semibold">{plan.name}</p>
                                    <p className="ir-text-xs ir-text-muted-foreground">{plan.monthly_price != null ? `${plan.monthly_price} ${plan.currency}/mes` : 'Sin precio'}</p>
                                </div>
                                <div className="ir-flex ir-gap-1">
                                    <button type="button" className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground" title="Editar" onClick={() => setEditing(plan)}>
                                        <Pencil className="ir-size-3.5" />
                                    </button>
                                    <button type="button" className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-danger/10 hover:ir-text-danger" title="Eliminar" onClick={() => { if (window.confirm(`¿Eliminar el plan «${plan.name}»?`)) remove.mutate(plan.id); }}>
                                        <Trash2 className="ir-size-3.5" />
                                    </button>
                                </div>
                            </div>
                            <ul className="ir-flex ir-flex-col ir-gap-0.5 ir-text-xs ir-text-muted-foreground">
                                {NUM_FIELDS.map((f) => (
                                    <li key={f.key}>{f.label}: <span className="ir-text-foreground">{plan[f.key] ?? '∞'}</span></li>
                                ))}
                            </ul>
                            <div className="ir-flex ir-flex-wrap ir-gap-1">
                                {FEATURES.filter((f) => plan.features?.[f.key]).map((f) => (
                                    <Badge key={f.key} tone="accent">{f.label}</Badge>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}
            {creating && <PlanModal plan={null} onClose={() => setCreating(false)} />}
            {editing !== null && <PlanModal plan={editing} onClose={() => setEditing(null)} />}
        </div>
    );
}

/* ---------------------------------- Screen --------------------------------- */

export function PlatformScreen(): ReactElement {
    const [tab, setTab] = useState<'agencies' | 'plans' | 'system'>('agencies');

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            <div>
                <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Plataforma</h1>
                <p className="ir-mt-1 ir-text-sm ir-text-muted-foreground">Gestiona las agencias de tu plataforma, sus planes y límites.</p>
            </div>
            <div className="ir-flex ir-gap-1 ir-rounded-lg ir-bg-muted ir-p-1 ir-self-start">
                {([['agencies', 'Agencias', Building2], ['plans', 'Planes', LayoutGrid], ['system', 'Sistema', DownloadCloud]] as const).map(([key, label, Icon]) => (
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
            {tab === 'agencies' && <AgenciesTab />}
            {tab === 'plans' && <PlansTab />}
            {tab === 'system' && <SystemUpdatePanel />}
        </div>
    );
}
