import { ArrowUpRight, Building2, Clock, ExternalLink, FileBarChart, Globe, Lock, MoreHorizontal, Pencil, Plug, Plus, Search, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactElement, useMemo, useState } from 'react';

import {
    useAgency,
    useClients,
    useCreateClient,
    useCreateSite,
    useDeleteClient,
    useDeleteSite,
    useOverview,
    useSites,
    useUpdateClient,
    useUpdateSite,
} from '../api';
import { SiteDataSources } from '../components/SiteDataSources';
import { ActionMenu, Badge, Button, Card, Field, Input, Modal, PageHeader, Segmented, Select, StatusDot } from '../components/ui';
import { CURRENCIES } from '../currencies';
import { useAdminUi } from '../store';
import { TIMEZONES } from '../timezones';
import type { AgencySettings, Client, Site } from '../types';

type ModalState =
    | { kind: 'new-client' }
    | { kind: 'add-site'; clientId: number }
    | { kind: 'edit-client'; client: Client }
    | { kind: 'edit-site'; site: Site }
    | null;

/* --------------------------------- modals --------------------------------- */

/** Express new client: creates the client and (optionally) its first site in one go. */
function NewClientModal({ onClose, onCreatedSite, onCreatedClient }: { onClose: () => void; onCreatedSite: (siteId: number) => void; onCreatedClient: (clientId: number) => void }): ReactElement {
    const createClient = useCreateClient();
    const createSite = useCreateSite();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [siteName, setSiteName] = useState('');
    const [url, setUrl] = useState('');
    const [currency, setCurrency] = useState('USD');
    const [error, setError] = useState<string | null>(null);

    const pending = createClient.isPending || createSite.isPending;

    const submit = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        setError(null);

        if (name.trim() === '') {
            setError('Pon un nombre de cliente.');
            return;
        }
        const wantsSite = siteName.trim() !== '' || url.trim() !== '';
        if (wantsSite && !/^https?:\/\//.test(url.trim())) {
            setError('La URL del sitio debe empezar por http:// o https://');
            return;
        }

        try {
            const client = await createClient.mutateAsync({ name: name.trim(), contact_email: email.trim() || undefined });
            if (wantsSite) {
                const site = await createSite.mutateAsync({ client_id: client.id, name: siteName.trim() || url.trim(), url: url.trim(), currency });
                onCreatedSite(site.id);
            } else {
                onCreatedClient(client.id);
            }
            onClose();
        } catch {
            setError('No se pudo crear. Revisa los datos e inténtalo de nuevo.');
        }
    };

    return (
        <Modal onClose={onClose}>
            <Card title="Nuevo cliente" description="Crea el cliente y, si quieres, su primer sitio. Las fuentes se añaden después.">
                <form onSubmit={(event) => void submit(event)} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Nombre del cliente">
                        <Input value={name} autoFocus onChange={(event) => setName(event.target.value)} placeholder="Acme S.L." />
                    </Field>
                    <Field label="Email de contacto (opcional)">
                        <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
                    </Field>

                    <div className="ir-mt-1 ir-rounded-md ir-border ir-bg-muted/20 ir-p-3">
                        <p className="ir-mb-2 ir-text-xs ir-font-medium ir-text-muted-foreground">Primer sitio (opcional — puedes añadirlo luego)</p>
                        <div className="ir-flex ir-flex-col ir-gap-3">
                            <Field label="Nombre del sitio">
                                <Input value={siteName} onChange={(event) => setSiteName(event.target.value)} placeholder="Tienda Acme" />
                            </Field>
                            <Field label="URL">
                                <Input value={url} onChange={(event) => setUrl(event.target.value)} placeholder="https://tienda.cliente.com" />
                            </Field>
                            <Field label="Moneda">
                                <Select value={currency} onChange={(event) => setCurrency(event.target.value)}>
                                    {CURRENCIES.map((option) => (
                                        <option key={option.code} value={option.code}>
                                            {option.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </div>

                    {error !== null && <p className="ir-text-xs ir-text-danger">{error}</p>}

                    <div className="ir-flex ir-gap-2">
                        <Button type="submit" disabled={pending}>
                            {pending ? 'Creando…' : 'Crear'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

/** Add a site to an existing client. */
function AddSiteModal({ clientId, clientName, onClose, onCreated }: { clientId: number; clientName: string; onClose: () => void; onCreated: (siteId: number) => void }): ReactElement {
    const createSite = useCreateSite();
    const [name, setName] = useState('');
    const [url, setUrl] = useState('');
    const [currency, setCurrency] = useState('USD');
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        setError(null);
        if (!/^https?:\/\//.test(url.trim())) {
            setError('La URL debe empezar por http:// o https://');
            return;
        }
        try {
            const site = await createSite.mutateAsync({ client_id: clientId, name: name.trim() || url.trim(), url: url.trim(), currency });
            onCreated(site.id);
            onClose();
        } catch {
            setError('No se pudo crear el sitio.');
        }
    };

    return (
        <Modal onClose={onClose}>
            <Card title="Nuevo sitio" description={`Para ${clientName}.`}>
                <form onSubmit={(event) => void submit(event)} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Nombre del sitio">
                        <Input value={name} autoFocus onChange={(event) => setName(event.target.value)} placeholder="Tienda Acme" />
                    </Field>
                    <Field label="URL">
                        <Input value={url} onChange={(event) => setUrl(event.target.value)} placeholder="https://…" />
                    </Field>
                    <Field label="Moneda">
                        <Select value={currency} onChange={(event) => setCurrency(event.target.value)}>
                            {CURRENCIES.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {error !== null && <p className="ir-text-xs ir-text-danger">{error}</p>}
                    <div className="ir-flex ir-gap-2">
                        <Button type="submit" disabled={createSite.isPending}>
                            Crear sitio
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

/** Edit an existing client (name, email, idioma, zona horaria, notas). */
function EditClientModal({ client, onClose }: { client: Client; onClose: () => void }): ReactElement {
    const update = useUpdateClient(client.id);
    const [name, setName] = useState(client.name);
    const [email, setEmail] = useState(client.contact_email ?? '');
    const [locale, setLocale] = useState(client.locale ?? '');
    const [timezone, setTimezone] = useState(client.timezone ?? '');
    const [notes, setNotes] = useState(client.notes ?? '');

    const save = (event: FormEvent): void => {
        event.preventDefault();
        update.mutate(
            {
                name,
                contact_email: email === '' ? null : email,
                locale: locale === '' ? null : locale,
                timezone: timezone === '' ? null : timezone,
                notes: notes === '' ? null : notes,
            },
            { onSuccess: onClose },
        );
    };

    return (
        <Modal onClose={onClose}>
            <Card title={`Editar «${client.name}»`}>
                <form onSubmit={save} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Nombre">
                        <Input value={name} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="Email de contacto">
                        <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
                    </Field>
                    <Field label="Idioma (ej. es, en, pt-BR)">
                        <Input value={locale} onChange={(event) => setLocale(event.target.value)} placeholder="es" />
                    </Field>
                    <Field label="Zona horaria (para fechas en los informes)">
                        <Select value={timezone} onChange={(event) => setTimezone(event.target.value)}>
                            <option value="">Sin definir (UTC)</option>
                            {TIMEZONES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Notas">
                        <Input value={notes} onChange={(event) => setNotes(event.target.value)} />
                    </Field>
                    <div className="ir-flex ir-gap-2">
                        <Button type="submit" disabled={update.isPending}>
                            Guardar cambios
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

/** Edit an existing site (name, URL, currency, plan hours). */
function EditSiteModal({ site, onClose }: { site: Site; onClose: () => void }): ReactElement {
    const update = useUpdateSite(site.id);
    const [name, setName] = useState(site.name);
    const [url, setUrl] = useState(site.url);
    const [currency, setCurrency] = useState(site.currency);
    const [planHours, setPlanHours] = useState(site.plan_hours ?? '');

    const save = (event: FormEvent): void => {
        event.preventDefault();
        update.mutate({ name, url, currency, plan_hours: planHours === '' ? null : Number(planHours) }, { onSuccess: onClose });
    };

    return (
        <Modal onClose={onClose}>
            <Card title={`Editar «${site.name}»`}>
                <form onSubmit={save} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Nombre">
                        <Input value={name} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="URL">
                        <Input value={url} onChange={(event) => setUrl(event.target.value)} />
                    </Field>
                    <Field label="Moneda del sitio">
                        <Select value={currency} onChange={(event) => setCurrency(event.target.value)}>
                            {CURRENCIES.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Horas de plan/mes (opcional)">
                        <Input type="number" min="0" step="0.5" value={planHours} onChange={(event) => setPlanHours(event.target.value)} placeholder="Ej. 5" />
                    </Field>
                    <div className="ir-flex ir-gap-2">
                        <Button type="submit" disabled={update.isPending}>
                            Guardar cambios
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

/* ------------------------------ entitlements ------------------------------ */

interface Entitlement {
    /** true = this resource is capped and already full (or there is no plan). */
    reached: boolean;
    used: number;
    /** null = unlimited. */
    limit: number | null;
    /** short "3 / 5" (or "3" when unlimited) for the usage chip. */
    label: string;
}

function gauge(used: number, limit: number | null): Entitlement {
    return {
        used,
        limit,
        reached: limit !== null && used >= limit,
        label: limit === null ? String(used) : `${used} / ${limit}`,
    };
}

/**
 * Derives what the current agency may create from its plan entitlements (mirrors the
 * backend Entitlements service). A missing plan means every limit is 0 → nothing can be
 * created until a plan is active, which is exactly what the banner explains.
 */
function useEntitlements(agency: AgencySettings | undefined): {
    ready: boolean;
    hasPlan: boolean;
    suspended: boolean;
    clients: Entitlement;
    sites: Entitlement;
} {
    return useMemo(() => {
        if (agency === undefined) {
            return { ready: false, hasPlan: true, suspended: false, clients: gauge(0, null), sites: gauge(0, null) };
        }

        return {
            ready: true,
            hasPlan: agency.plan !== null,
            suspended: agency.status !== 'active',
            clients: gauge(agency.usage.clients, agency.limits.max_clients),
            sites: gauge(agency.usage.sites, agency.limits.max_sites),
        };
    }, [agency]);
}

/** Small "3 / 5" pill; turns amber when the cap is reached so the limit is visible at a glance. */
function UsageChip({ label, entitlement, reached }: { label: string; entitlement: Entitlement; reached: boolean }): ReactElement {
    return (
        <span
            className={
                'ir-inline-flex ir-items-center ir-gap-1 ir-rounded-full ir-px-2 ir-py-0.5 ir-text-[11px] ir-font-medium ' +
                (reached ? 'ir-bg-amber-500/15 ir-text-amber-600' : 'ir-bg-muted ir-text-muted-foreground')
            }
            title={entitlement.limit === null ? `${label}: sin límite en tu plan` : `${label}: ${entitlement.used} de ${entitlement.limit} usados`}
        >
            {label} {entitlement.label}
        </span>
    );
}

/**
 * Explains why creation is blocked and links to the plan. Shown when the agency has no
 * active plan, is suspended, or has hit a limit — so nothing ever silently fails and the
 * user knows the app is enforcing the plan, not broken.
 */
function PlanLimitNotice({ tone, title, body }: { tone: 'danger' | 'warning'; title: string; body: string }): ReactElement {
    const setView = useAdminUi((state) => state.setView);
    const danger = tone === 'danger';

    return (
        <div
            className={
                'ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 ir-rounded-xl ir-border ir-px-4 ir-py-3 ' +
                (danger ? 'ir-border-danger/30 ir-bg-danger/5' : 'ir-border-amber-500/30 ir-bg-amber-500/5')
            }
        >
            <div className="ir-flex ir-min-w-0 ir-items-start ir-gap-2.5">
                <span className={'ir-mt-0.5 ir-shrink-0 ' + (danger ? 'ir-text-danger' : 'ir-text-amber-600')}>
                    <Lock className="ir-size-4" />
                </span>
                <div className="ir-min-w-0">
                    <p className="ir-text-sm ir-font-semibold ir-text-foreground">{title}</p>
                    <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">{body}</p>
                </div>
            </div>
            <Button size="sm" variant={danger ? 'primary' : 'ghost'} onClick={() => setView('settings')}>
                Ver planes
                <ArrowUpRight className="ir-size-3.5" />
            </Button>
        </div>
    );
}

/* --------------------------------- health ---------------------------------- */

type SiteHealth = { sources: number; errors: number; health_score: number | null };

const SITE_STATUS: Record<string, string> = { active: 'Activo', paused: 'Pausado', inactive: 'Inactivo', archived: 'Archivado' };

function healthTone(health: SiteHealth | undefined): 'success' | 'warning' | 'danger' | 'neutral' {
    if (health === undefined || health.sources === 0) {
        return 'neutral';
    }
    if (health.errors > 0) {
        return 'danger';
    }

    return health.health_score !== null && health.health_score < 60 ? 'warning' : 'success';
}

function scoreClass(score: number | null): string {
    if (score === null) {
        return 'ir-text-muted-foreground';
    }

    return score >= 80 ? 'ir-text-success' : score >= 50 ? 'ir-text-warning' : 'ir-text-danger';
}

/** Small circular gauge for a 0–100 health score. */
function ScoreRing({ score, size = 44 }: { score: number | null; size?: number }): ReactElement {
    const radius = (size - 6) / 2;
    const circumference = 2 * Math.PI * radius;
    const value = score ?? 0;
    const color = score === null ? 'hsl(var(--ir-border))' : value >= 80 ? 'hsl(var(--ir-success))' : value >= 50 ? 'hsl(var(--ir-warning))' : 'hsl(var(--ir-danger))';

    return (
        <span className="ir-relative ir-inline-flex ir-shrink-0 ir-items-center ir-justify-center" style={{ width: size, height: size }} title="Salud del último reporte">
            <svg width={size} height={size} className="-ir-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="hsl(var(--ir-muted))" strokeWidth={4} />
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke={color} strokeWidth={4} strokeLinecap="round" strokeDasharray={circumference} strokeDashoffset={circumference * (1 - value / 100)} />
            </svg>
            <span className={`ir-absolute ir-text-xs ir-font-semibold ir-tabular-nums ${scoreClass(score)}`}>{score ?? '—'}</span>
        </span>
    );
}

/** The portfolio at a glance (shown when no site is selected): one card per site. */
function SiteGrid({
    sites,
    clients,
    health,
    onSelect,
    onNewClient,
}: {
    sites: Site[];
    clients: Client[];
    health: Record<string, SiteHealth>;
    onSelect: (siteId: number) => void;
    onNewClient: () => void;
}): ReactElement {
    if (sites.length === 0) {
        return (
            <section className="ir-flex ir-flex-col ir-items-center ir-gap-3 ir-rounded-xl ir-border ir-border-dashed ir-bg-card ir-px-6 ir-py-20 ir-text-center">
                <span className="ir-flex ir-size-12 ir-items-center ir-justify-center ir-rounded-xl ir-bg-muted ir-text-muted-foreground">
                    <Building2 className="ir-size-6" />
                </span>
                <div>
                    <p className="ir-text-sm ir-font-semibold">Tu cartera está vacía</p>
                    <p className="ir-mt-1 ir-max-w-xs ir-text-[13px] ir-text-muted-foreground">Crea tu primer cliente con su sitio web. Después conectas sus fuentes y generas su reporte.</p>
                </div>
                <Button onClick={onNewClient}>
                    <Plus className="ir-size-4" />
                    Nuevo cliente
                </Button>
            </section>
        );
    }

    return (
        <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 xl:ir-grid-cols-3 min-[1700px]:ir-grid-cols-4">
            {sites.map((site) => {
                const h = health[String(site.id)];
                const client = clients.find((item) => item.id === site.client_id);

                return (
                    <button
                        key={site.id}
                        type="button"
                        onClick={() => onSelect(site.id)}
                        className="ir-group ir-flex ir-flex-col ir-gap-3 ir-rounded-xl ir-border ir-bg-card ir-p-4 ir-text-left ir-shadow-ir-xs ir-transition hover:ir-border-foreground/20 hover:ir-shadow-ir-sm"
                    >
                        <div className="ir-flex ir-items-start ir-justify-between ir-gap-3">
                            <div className="ir-min-w-0">
                                <p className="ir-truncate ir-text-sm ir-font-semibold">{site.name}</p>
                                <p className="ir-truncate ir-text-xs ir-text-muted-foreground">
                                    {client?.name ?? ''} · {site.url.replace(/^https?:\/\//, '')}
                                </p>
                            </div>
                            <ScoreRing score={h?.health_score ?? null} size={40} />
                        </div>
                        <div className="ir-flex ir-items-center ir-gap-2 ir-text-xs ir-text-muted-foreground">
                            <StatusDot tone={healthTone(h)} />
                            {h === undefined || h.sources === 0 ? (
                                <span>Sin fuentes conectadas</span>
                            ) : h.errors > 0 ? (
                                <span className="ir-text-danger">{h.errors} de {h.sources} fuentes con error</span>
                            ) : (
                                <span>{h.sources} fuentes funcionando</span>
                            )}
                        </div>
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------- main screen ------------------------------ */

export function WorkspaceScreen(): ReactElement {
    const { data: clients = [] } = useClients();
    const { data: sites = [] } = useSites();
    const { data: agency } = useAgency();
    const ent = useEntitlements(agency);
    const selectedSiteId = useAdminUi((state) => state.selectedSiteId);
    const selectSite = useAdminUi((state) => state.selectSite);
    const deleteClient = useDeleteClient();
    const deleteSite = useDeleteSite();

    const { data: overview } = useOverview();
    const health = overview?.site_health ?? {};
    const setView = useAdminUi((state) => state.setView);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<'all' | 'issues' | 'empty'>('all');
    const [modal, setModal] = useState<ModalState>(null);

    const sitesByClient = useMemo(() => {
        const map = new Map<number, Site[]>();
        for (const site of sites) {
            const list = map.get(site.client_id) ?? [];
            list.push(site);
            map.set(site.client_id, list);
        }
        return map;
    }, [sites]);

    const selectedSite = sites.find((site) => site.id === selectedSiteId) ?? null;
    const selectedClient = selectedSite !== null ? clients.find((client) => client.id === selectedSite.client_id) ?? null : null;


    const siteMatchesFilter = (site: Site): boolean => {
        const h = health[String(site.id)];
        if (filter === 'issues') {
            return h !== undefined && h.errors > 0;
        }
        if (filter === 'empty') {
            return h === undefined || h.sources === 0;
        }

        return true;
    };

    const filteredClients = useMemo(() => {
        const q = query.trim().toLowerCase();

        return [...clients]
            .sort((a, b) => a.name.localeCompare(b.name, 'es'))
            .filter((client) => {
                const own = sitesByClient.get(client.id) ?? [];
                if (filter !== 'all' && !own.some(siteMatchesFilter)) {
                    return false;
                }
                if (q === '') {
                    return true;
                }
                if (client.name.toLowerCase().includes(q)) {
                    return true;
                }
                return own.some((site) => site.name.toLowerCase().includes(q) || site.url.toLowerCase().includes(q));
            });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [clients, query, sitesByClient, filter, health]);

    const issuesCount = sites.filter((site) => (health[String(site.id)]?.errors ?? 0) > 0).length;
    const emptyCount = sites.filter((site) => (health[String(site.id)]?.sources ?? 0) === 0).length;
    const gridSites = sites.filter((site) => siteMatchesFilter(site) && filteredClients.some((client) => client.id === site.client_id));


    const removeClient = (client: Client): void => {
        if (window.confirm(`¿Eliminar el cliente «${client.name}»? (No se puede si todavía tiene sitios.)`)) {
            deleteClient.mutate(client.id, {
                onError: () => window.alert('No se pudo eliminar: el cliente todavía tiene sitios. Elimínalos o reasígnalos primero.'),
            });
        }
    };

    const removeSite = (site: Site): void => {
        if (
            window.confirm(
                `¿Eliminar el sitio «${site.name}»?\n\nSe borrarán también sus fuentes de datos, métricas sincronizadas, informes y registros de trabajo. Esta acción no se puede deshacer.`,
            )
        ) {
            // After the refetch the deleted site drops out of the list, so the detail
            // panel clears itself — no need to touch the selection here.
            deleteSite.mutate(site.id, {
                onError: () => window.alert('No se pudo eliminar el sitio. Inténtalo de nuevo.'),
            });
        }
    };

    // Why creation is (or isn't) allowed — drives the banner and the disabled buttons.
    const blocked = ent.ready && (!ent.hasPlan || ent.suspended);
    const notice = !ent.ready
        ? null
        : ent.suspended
          ? { tone: 'danger' as const, title: 'Tu agencia está suspendida', body: 'Regulariza el pago de tu plan para volver a crear clientes, sitios e informes.' }
          : !ent.hasPlan
            ? { tone: 'danger' as const, title: 'Aún no tienes un plan activo', body: 'Suscríbete a un plan para empezar a crear clientes y sitios. Mientras tanto, la creación está bloqueada.' }
            : ent.clients.reached && ent.sites.reached
              ? { tone: 'warning' as const, title: 'Has alcanzado los límites de tu plan', body: 'Llegaste al máximo de clientes y sitios. Mejora tu plan para añadir más.' }
              : null;

    const clientsBlocked = blocked || ent.clients.reached;

    return (
        <div className="ir-flex ir-flex-col">
            <PageHeader
                title="Clientes"
                description="Las empresas a las que reportas y sus sitios. Elige un sitio para conectar sus fuentes y ver su estado."
                eyebrow={
                    ent.ready ? (
                        <span className="ir-flex ir-gap-1.5">
                            <UsageChip label="Clientes" entitlement={ent.clients} reached={clientsBlocked} />
                            <UsageChip label="Sitios" entitlement={ent.sites} reached={blocked || ent.sites.reached} />
                        </span>
                    ) : undefined
                }
                actions={
                    <Button
                        disabled={clientsBlocked}
                        title={clientsBlocked ? (ent.hasPlan ? 'Has alcanzado el límite de clientes de tu plan.' : 'Suscríbete a un plan para crear clientes.') : undefined}
                        onClick={() => setModal({ kind: 'new-client' })}
                    >
                        <Plus className="ir-size-4" />
                        Nuevo cliente
                    </Button>
                }
            />

            {notice !== null && (
                <div className="ir-mb-5">
                    <PlanLimitNotice tone={notice.tone} title={notice.title} body={notice.body} />
                </div>
            )}

            <div className="ir-grid ir-gap-5 lg:ir-grid-cols-[290px_minmax(0,1fr)] 2xl:ir-grid-cols-[320px_minmax(0,1fr)]">
            {/* Left: clients → sites tree */}
            {/* On phones the list and the detail take turns: the list until a site is picked. */}
            <aside className={'ir-self-start ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-xs lg:ir-sticky lg:ir-top-4 ' + (selectedSite !== null ? 'ir-hidden lg:ir-block' : '')}>
                <div className="ir-flex ir-flex-col ir-gap-2.5 ir-border-b ir-p-3">
                    <div className="ir-relative">
                        <Search className="ir-pointer-events-none ir-absolute ir-left-2.5 ir-top-1/2 ir-size-4 -ir-translate-y-1/2 ir-text-muted-foreground" />
                        <Input className="ir-pl-8" placeholder="Buscar cliente o sitio…" value={query} onChange={(event) => setQuery(event.target.value)} />
                    </div>
                    <Segmented
                        fill
                        value={filter}
                        onChange={setFilter}
                        items={[
                            { value: 'all', label: 'Todos', count: sites.length },
                            { value: 'issues', label: 'Con error', count: issuesCount },
                            { value: 'empty', label: 'Sin fuentes', count: emptyCount },
                        ]}
                    />
                </div>

                {/* Every site is always visible, grouped under its client — no expanding
                    clients one by one. Rows have a fixed height, so hover never shifts them. */}
                <div className="ir-max-h-[calc(100vh-15rem)] ir-overflow-y-auto ir-px-2 ir-pb-2">
                    {filteredClients.map((client) => {
                        const allSites = sitesByClient.get(client.id) ?? [];
                        const clientSites = allSites.filter(siteMatchesFilter);

                        return (
                            <section key={client.id} className="ir-pt-2">
                                <div className="ir-group ir-flex ir-h-8 ir-items-center ir-gap-2 ir-px-2">
                                    <span className="ir-min-w-0 ir-flex-1 ir-truncate ir-text-xs ir-font-semibold ir-text-foreground/70" title={client.name}>
                                        {client.name}
                                    </span>
                                    <span className="ir-flex ir-shrink-0 ir-items-center ir-opacity-0 ir-transition-opacity focus-within:ir-opacity-100 group-hover:ir-opacity-100">
                                        <button
                                            type="button"
                                            title="Añadir sitio a este cliente"
                                            disabled={blocked || ent.sites.reached}
                                            onClick={() => setModal({ kind: 'add-site', clientId: client.id })}
                                            className="ir-flex ir-size-6 ir-items-center ir-justify-center ir-rounded-md ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground disabled:ir-opacity-40"
                                        >
                                            <Plus className="ir-size-3.5" />
                                        </button>
                                        <ActionMenu
                                            label={`Acciones de ${client.name}`}
                                            trigger={
                                                <span className="ir-flex ir-size-6 ir-items-center ir-justify-center ir-rounded-md ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                                                    <MoreHorizontal className="ir-size-3.5" />
                                                </span>
                                            }
                                            items={[
                                                { label: 'Añadir sitio', icon: Plus, onSelect: () => setModal({ kind: 'add-site', clientId: client.id }), disabled: blocked || ent.sites.reached },
                                                { label: 'Editar cliente', icon: Pencil, onSelect: () => setModal({ kind: 'edit-client', client }) },
                                                { label: 'Eliminar cliente', icon: Trash2, onSelect: () => removeClient(client), danger: true },
                                            ]}
                                        />
                                    </span>
                                </div>

                                {clientSites.map((site) => {
                                    const active = site.id === selectedSiteId;
                                    const h = health[String(site.id)];

                                    return (
                                        <button
                                            key={site.id}
                                            type="button"
                                            onClick={() => selectSite(site.id)}
                                            className={
                                                'ir-flex ir-h-9 ir-w-full ir-items-center ir-gap-2.5 ir-rounded-lg ir-px-2 ir-text-left ir-text-sm ir-transition-colors ' +
                                                (active ? 'ir-bg-accent/10 ir-font-medium ir-text-accent' : 'ir-text-foreground hover:ir-bg-muted/70')
                                            }
                                            title={site.url}
                                        >
                                            <StatusDot tone={healthTone(h)} />
                                            <span className="ir-min-w-0 ir-flex-1 ir-truncate">{site.name}</span>
                                            {h?.health_score != null ? (
                                                <span className={`ir-shrink-0 ir-text-[11px] ir-font-semibold ir-tabular-nums ${scoreClass(h.health_score)}`}>{h.health_score}</span>
                                            ) : (h?.sources ?? 0) === 0 ? (
                                                <span className="ir-shrink-0 ir-text-[11px] ir-text-muted-foreground">sin fuentes</span>
                                            ) : null}
                                        </button>
                                    );
                                })}

                                {allSites.length === 0 && filter === 'all' && (
                                    <button
                                        type="button"
                                        disabled={blocked || ent.sites.reached}
                                        onClick={() => setModal({ kind: 'add-site', clientId: client.id })}
                                        className="ir-flex ir-h-9 ir-w-full ir-items-center ir-gap-2 ir-rounded-lg ir-px-2 ir-text-left ir-text-xs ir-text-muted-foreground hover:ir-bg-muted/70 hover:ir-text-foreground disabled:ir-opacity-50"
                                    >
                                        <Plus className="ir-size-3.5" />
                                        Añadir su primer sitio
                                    </button>
                                )}
                            </section>
                        );
                    })}

                    {filteredClients.length === 0 && (
                        <p className="ir-mt-2 ir-rounded-lg ir-border ir-border-dashed ir-p-4 ir-text-center ir-text-xs ir-text-muted-foreground">
                            {clients.length === 0 ? 'Aún no hay clientes. Crea el primero con «Nuevo cliente».' : 'Nada coincide con la búsqueda o el filtro.'}
                        </p>
                    )}
                </div>
            </aside>

            {/* Right: selected site detail + its data sources, or the whole portfolio */}
            {selectedSite !== null ? (
                <section className="ir-min-w-0 ir-self-start ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-xs">
                    <header className="ir-flex ir-flex-col ir-gap-4 ir-border-b ir-px-5 ir-py-5">
                        <div className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3">
                            <div className="ir-flex ir-min-w-0 ir-items-center ir-gap-3">
                                <ScoreRing score={health[String(selectedSite.id)]?.health_score ?? null} size={48} />
                                <div className="ir-min-w-0">
                                    <button type="button" onClick={() => selectSite(0)} className="ir-text-xs ir-font-medium ir-text-muted-foreground hover:ir-text-foreground" title="Volver a la cartera">
                                        ← {selectedClient?.name ?? 'Cartera'}
                                    </button>
                                    <h2 className="ir-truncate ir-text-lg ir-font-semibold ir-tracking-tight">{selectedSite.name}</h2>
                                </div>
                            </div>
                            <div className="ir-flex ir-items-center ir-gap-2">
                                <Button variant="ghost" size="sm" onClick={() => setView('worklogs')}>
                                    <Clock className="ir-size-3.5" />
                                    Registrar trabajo
                                </Button>
                                <Button size="sm" onClick={() => setView('reports')}>
                                    <FileBarChart className="ir-size-3.5" />
                                    Reportes
                                </Button>
                                <ActionMenu
                                    label="Más acciones del sitio"
                                    items={[
                                        { label: 'Editar sitio', icon: Pencil, onSelect: () => setModal({ kind: 'edit-site', site: selectedSite }) },
                                        { label: 'Eliminar sitio', icon: Trash2, onSelect: () => removeSite(selectedSite), danger: true, disabled: deleteSite.isPending },
                                    ]}
                                />
                            </div>
                        </div>
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                            <a
                                href={selectedSite.url}
                                target="_blank"
                                rel="noreferrer"
                                className="ir-inline-flex ir-max-w-full ir-items-center ir-gap-1.5 ir-rounded-full ir-border ir-px-2.5 ir-py-1 ir-text-xs ir-text-foreground/80 ir-transition hover:ir-bg-muted hover:ir-text-foreground"
                            >
                                <Globe className="ir-size-3 ir-shrink-0" />
                                <span className="ir-truncate">{selectedSite.url.replace(/^https?:\/\//, '')}</span>
                                <ExternalLink className="ir-size-3 ir-shrink-0 ir-opacity-60" />
                            </a>
                            <Badge tone={selectedSite.status === 'active' ? 'success' : 'neutral'}>{SITE_STATUS[selectedSite.status] ?? selectedSite.status}</Badge>
                            <Badge tone="neutral">{selectedSite.currency}</Badge>
                            {selectedSite.support_plan !== null && selectedSite.support_plan !== '' && <Badge tone="neutral">Plan {selectedSite.support_plan}</Badge>}
                            {selectedSite.plan_hours != null && <Badge tone="info">{selectedSite.plan_hours} h/mes</Badge>}
                        </div>
                    </header>

                    <div className="ir-px-5 ir-py-5">
                        <SiteDataSources siteId={selectedSite.id} siteName={selectedSite.name} />
                    </div>
                </section>
            ) : (
                <div className="ir-hidden ir-min-w-0 ir-flex-col ir-gap-3 lg:ir-flex">
                    <div className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                        <Plug className="ir-size-4" />
                        {filter === 'issues' ? 'Sitios con alguna fuente fallando' : filter === 'empty' ? 'Sitios a los que aún no les conectaste fuentes' : 'Todos tus sitios. Elige uno para ver y configurar sus fuentes.'}
                    </div>
                    <SiteGrid sites={gridSites} clients={clients} health={health} onSelect={selectSite} onNewClient={() => setModal({ kind: 'new-client' })} />
                </div>
            )}
            </div>

            {/* Modals */}
            {modal?.kind === 'new-client' && (
                <NewClientModal
                    onClose={() => setModal(null)}
                    onCreatedSite={(siteId) => selectSite(siteId)}
                    onCreatedClient={() => undefined}
                />
            )}
            {modal?.kind === 'add-site' && (
                <AddSiteModal
                    clientId={modal.clientId}
                    clientName={clients.find((client) => client.id === modal.clientId)?.name ?? ''}
                    onClose={() => setModal(null)}
                    onCreated={(siteId) => selectSite(siteId)}
                />
            )}
            {modal?.kind === 'edit-client' && <EditClientModal client={modal.client} onClose={() => setModal(null)} />}
            {modal?.kind === 'edit-site' && <EditSiteModal site={modal.site} onClose={() => setModal(null)} />}
        </div>
    );
}
