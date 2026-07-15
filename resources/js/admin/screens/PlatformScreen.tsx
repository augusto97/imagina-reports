import { Building2, CreditCard, DownloadCloud, LayoutGrid, LogIn, Pencil, Plug, Plus, Power, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactElement, useEffect, useState } from 'react';

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
    useDeletePlan,
    useImpersonateAgency,
    usePlatformAgencies,
    usePlatformPlans,
    useUpdatePlan,
    useUpdatePlatformAgency,
} from '../api';
import { Badge, Button, Card, Field, Input, Modal, Select } from '../components/ui';
import type { Plan, PlatformAgency } from '../types';

// Currencies MercadoPago (local) + PayPal (USD) commonly support in the region.
const CURRENCIES = ['USD', 'ARS', 'MXN', 'COP', 'CLP', 'PEN', 'BRL', 'UYU', 'EUR'];

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

/* --------------------------------- Billing --------------------------------- */

function BillingTab(): ReactElement {
    const { data: settings } = usePlatformBillingSettings();
    const update = useUpdatePlatformBillingSettings();
    const [mp, setMp] = useState('');
    const [ppId, setPpId] = useState('');
    const [ppSecret, setPpSecret] = useState('');
    const [ppHook, setPpHook] = useState('');

    const save = (payload: { mercadopago_access_token?: string; paypal_client_id?: string; paypal_secret?: string; paypal_webhook_id?: string; billing_sandbox?: boolean }): void => {
        update.mutate(payload, { onSuccess: () => { setMp(''); setPpId(''); setPpSecret(''); setPpHook(''); } });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <Card title="MercadoPago" description="Cobros recurrentes en la moneda local de cada plan. Pega tu Access Token (se guarda cifrado).">
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <p className="ir-text-xs ir-text-muted-foreground">
                        Estado: {settings?.mercadopago_configured ? <span className="ir-font-medium ir-text-emerald-600">configurado ✓</span> : <span className="ir-font-medium ir-text-amber-600">sin configurar</span>}
                    </p>
                    <Field label="Access Token">
                        <Input type="password" autoComplete="off" value={mp} onChange={(e) => setMp(e.target.value)} placeholder={settings?.mercadopago_configured ? '•••••••• (deja en blanco para conservar)' : 'APP_USR-…'} />
                    </Field>
                    <Button className="ir-self-start" onClick={() => save({ mercadopago_access_token: mp })} disabled={update.isPending || mp === ''}>
                        Guardar
                    </Button>
                </div>
            </Card>

            <Card title="PayPal" description="Suscripciones recurrentes. Client ID + Secret de tu app REST de PayPal.">
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <p className="ir-text-xs ir-text-muted-foreground">
                        Estado: {settings?.paypal_configured ? <span className="ir-font-medium ir-text-emerald-600">configurado ✓</span> : <span className="ir-font-medium ir-text-amber-600">sin configurar</span>}
                    </p>
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
                        <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">
                            Webhook ID: {settings?.paypal_webhook_configured ? <span className="ir-font-medium ir-text-emerald-600">configurado ✓</span> : <span className="ir-font-medium ir-text-amber-600">sin configurar — los webhooks de PayPal se rechazan hasta configurarlo</span>}
                        </p>
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

            <Card title="Entorno">
                <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm">
                    <input type="checkbox" checked={settings?.billing_sandbox ?? true} onChange={(e) => save({ billing_sandbox: e.target.checked })} />
                    Modo sandbox (pruebas). Desactívalo solo cuando uses credenciales de producción.
                </label>
                {update.isSuccess && <p className="ir-mt-2 ir-text-xs ir-text-emerald-600">Guardado.</p>}
            </Card>
        </div>
    );
}

/* ------------------------------- Integrations ------------------------------ */

function statusChip(ready: boolean, fromEnv: boolean): ReactElement {
    if (ready) {
        return <span className="ir-font-medium ir-text-emerald-600">listo ✓{fromEnv ? ' (por .env)' : ''}</span>;
    }

    return <span className="ir-font-medium ir-text-amber-600">sin configurar</span>;
}

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

            <Card title="Google (GA4, Search Console, Google Ads)" description="Una sola app OAuth de Google Cloud cubre las tres fuentes.">
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <p className="ir-text-xs ir-text-muted-foreground">Estado: {statusChip(settings?.google_connect_ready ?? false, settings?.google_from_env ?? false)}</p>
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
                        <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">
                            Solo para <strong>Google Ads</strong>: developer token {settings?.google_ads_developer_token_set ? <span className="ir-text-emerald-600">✓</span> : <span className="ir-text-amber-600">sin configurar</span>}
                        </p>
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

            <Card title="Meta (Facebook / Instagram)" description="Una app de Meta cubre Facebook Ads e Instagram.">
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <p className="ir-text-xs ir-text-muted-foreground">Estado: {statusChip(settings?.meta_connect_ready ?? false, settings?.meta_from_env ?? false)}</p>
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
            {update.isSuccess && <p className="ir-text-xs ir-text-emerald-600">Guardado.</p>}

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
        <Card title="Correo saliente (SMTP)" description="Con qué servidor se envían los reportes por email. Se guarda cifrado; sustituye a las variables del .env.">
            <div className="ir-flex ir-flex-col ir-gap-3">
                <p className="ir-text-xs ir-text-muted-foreground">
                    Estado: {sends ? <span className="ir-font-medium ir-text-emerald-600">envía por «{settings?.mail_mailer}» ✓</span> : <span className="ir-font-medium ir-text-amber-600">sin configurar (los correos no salen, solo se registran en el log)</span>}
                </p>
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
                    {sendTest.isSuccess && sendTest.data?.sent && <p className="ir-mt-2 ir-text-xs ir-text-emerald-600">Enviado ✓ Revisa la bandeja (y spam).</p>}
                    {sendTest.data?.sent === false && (
                        <div className="ir-mt-2 ir-rounded ir-bg-danger/10 ir-p-2 ir-text-xs ir-text-danger">
                            <p className="ir-font-medium">No se pudo enviar. Error del servidor:</p>
                            <p className="ir-mt-1 ir-break-words ir-font-mono ir-text-[11px]">{sendTest.data.error}</p>
                        </div>
                    )}
                    {sendTest.isError && <p className="ir-mt-2 ir-text-xs ir-text-red-500">No se pudo contactar el servidor. Inténtalo de nuevo.</p>}
                </div>
            </div>
        </Card>
    );
}

/* ---------------------------------- Screen --------------------------------- */

export function PlatformScreen(): ReactElement {
    const [tab, setTab] = useState<'agencies' | 'plans' | 'billing' | 'integrations' | 'system'>('agencies');

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            <div>
                <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Plataforma</h1>
                <p className="ir-mt-1 ir-text-sm ir-text-muted-foreground">Gestiona las agencias de tu plataforma, sus planes y límites.</p>
            </div>
            <div className="ir-flex ir-gap-1 ir-rounded-lg ir-bg-muted ir-p-1 ir-self-start">
                {([['agencies', 'Agencias', Building2], ['plans', 'Planes', LayoutGrid], ['billing', 'Facturación', CreditCard], ['integrations', 'Integraciones', Plug], ['system', 'Sistema', DownloadCloud]] as const).map(([key, label, Icon]) => (
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
            {tab === 'billing' && <BillingTab />}
            {tab === 'integrations' && <IntegrationsTab />}
            {tab === 'system' && <SystemUpdatePanel />}
        </div>
    );
}
