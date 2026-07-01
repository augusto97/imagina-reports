import { ArrowUpRight, Building2, ChevronDown, ChevronRight, ExternalLink, Globe, Lock, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactElement, useMemo, useState } from 'react';

import {
    useAgency,
    useClients,
    useCreateClient,
    useCreateSite,
    useDeleteClient,
    useDeleteSite,
    useSites,
    useUpdateClient,
    useUpdateSite,
} from '../api';
import { SiteDataSources } from '../components/SiteDataSources';
import { Badge, Button, Card, Field, Input, Modal, Select } from '../components/ui';
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

    const [query, setQuery] = useState('');
    const [modal, setModal] = useState<ModalState>(null);
    const [expanded, setExpanded] = useState<Set<number>>(() => new Set());

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

    // Keep the owning client expanded so the selected site is always visible in the list.
    const effectiveExpanded = useMemo(() => {
        const set = new Set(expanded);
        if (selectedSite !== null) {
            set.add(selectedSite.client_id);
        }
        return set;
    }, [expanded, selectedSite]);

    const filteredClients = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (q === '') {
            return clients;
        }
        return clients.filter((client) => {
            if (client.name.toLowerCase().includes(q)) {
                return true;
            }
            return (sitesByClient.get(client.id) ?? []).some((site) => site.name.toLowerCase().includes(q) || site.url.toLowerCase().includes(q));
        });
    }, [clients, query, sitesByClient]);

    const toggle = (clientId: number): void => {
        setExpanded((current) => {
            const next = new Set(current);
            if (next.has(clientId)) {
                next.delete(clientId);
            } else {
                next.add(clientId);
            }
            return next;
        });
    };

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
        <div className="ir-flex ir-flex-col ir-gap-5">
            {notice !== null && <PlanLimitNotice tone={notice.tone} title={notice.title} body={notice.body} />}

            <div className="ir-grid ir-gap-5 lg:ir-grid-cols-[300px_1fr]">
            {/* Left: clients → sites tree */}
            <aside className="ir-self-start ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-sm lg:ir-sticky lg:ir-top-4">
                <div className="ir-flex ir-items-center ir-justify-between ir-gap-2 ir-border-b ir-px-4 ir-py-3">
                    <h2 className="ir-text-sm ir-font-semibold ir-tracking-tight">Clientes</h2>
                    <Button
                        size="sm"
                        disabled={clientsBlocked}
                        title={clientsBlocked ? (ent.hasPlan ? 'Has alcanzado el límite de clientes de tu plan.' : 'Suscríbete a un plan para crear clientes.') : undefined}
                        onClick={() => setModal({ kind: 'new-client' })}
                    >
                        <Plus className="ir-size-3.5" />
                        Nuevo
                    </Button>
                </div>

                {ent.ready && (
                    <div className="ir-flex ir-items-center ir-gap-1.5 ir-border-b ir-px-4 ir-py-2">
                        <UsageChip label="Clientes" entitlement={ent.clients} reached={clientsBlocked} />
                        <UsageChip label="Sitios" entitlement={ent.sites} reached={blocked || ent.sites.reached} />
                    </div>
                )}

                <div className="ir-p-3">
                    <div className="ir-relative ir-mb-2">
                        <Search className="ir-pointer-events-none ir-absolute ir-left-2.5 ir-top-1/2 ir-size-4 -ir-translate-y-1/2 ir-text-muted-foreground" />
                        <Input className="ir-pl-8" placeholder="Buscar cliente o sitio…" value={query} onChange={(event) => setQuery(event.target.value)} />
                    </div>

                    <ul className="ir-flex ir-flex-col ir-gap-0.5">
                        {filteredClients.map((client) => {
                            const clientSites = sitesByClient.get(client.id) ?? [];
                            const isOpen = effectiveExpanded.has(client.id);
                            const Chevron = isOpen ? ChevronDown : ChevronRight;

                            return (
                                <li key={client.id}>
                                    <div className="ir-group ir-flex ir-items-center ir-gap-1 ir-rounded-lg ir-px-1.5 ir-py-1.5 ir-transition-colors hover:ir-bg-muted/60">
                                        <button type="button" onClick={() => toggle(client.id)} className="ir-flex ir-min-w-0 ir-flex-1 ir-items-center ir-gap-1.5 ir-text-left">
                                            <Chevron className="ir-size-3.5 ir-shrink-0 ir-text-muted-foreground" />
                                            <Building2 className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                                            <span className="ir-truncate ir-text-sm ir-font-medium">{client.name}</span>
                                            <span className="ir-ml-auto ir-shrink-0 ir-rounded-full ir-bg-muted ir-px-1.5 ir-py-0.5 ir-text-[11px] ir-font-medium ir-text-muted-foreground group-hover:ir-hidden">
                                                {clientSites.length}
                                            </span>
                                        </button>
                                        <span className="ir-hidden ir-shrink-0 ir-items-center group-hover:ir-flex">
                                            <button type="button" title="Editar cliente" onClick={() => setModal({ kind: 'edit-client', client })} className="ir-rounded-md ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                                                <Pencil className="ir-size-3.5" />
                                            </button>
                                            <button type="button" title="Eliminar cliente" onClick={() => removeClient(client)} disabled={deleteClient.isPending} className="ir-rounded-md ir-p-1 ir-text-muted-foreground hover:ir-bg-danger/10 hover:ir-text-danger disabled:ir-opacity-50">
                                                <Trash2 className="ir-size-3.5" />
                                            </button>
                                        </span>
                                    </div>

                                    {isOpen && (
                                        <div className="ir-mb-1 ir-ml-[1.05rem] ir-flex ir-flex-col ir-gap-0.5 ir-border-l ir-pl-2">
                                            {clientSites.map((site) => {
                                                const active = site.id === selectedSiteId;

                                                return (
                                                    <button
                                                        key={site.id}
                                                        type="button"
                                                        onClick={() => selectSite(site.id)}
                                                        className={
                                                            'ir-flex ir-items-center ir-gap-1.5 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-left ir-text-sm ir-transition-colors ' +
                                                            (active ? 'ir-bg-accent/10 ir-font-medium ir-text-accent' : 'ir-text-foreground/80 hover:ir-bg-muted/60')
                                                        }
                                                    >
                                                        <Globe className={'ir-size-3.5 ir-shrink-0 ' + (active ? 'ir-text-accent' : 'ir-text-muted-foreground')} />
                                                        <span className="ir-truncate">{site.name}</span>
                                                    </button>
                                                );
                                            })}
                                            <button
                                                type="button"
                                                disabled={blocked || ent.sites.reached}
                                                title={blocked || ent.sites.reached ? (ent.hasPlan ? 'Has alcanzado el límite de sitios de tu plan.' : 'Suscríbete a un plan para crear sitios.') : undefined}
                                                onClick={() => setModal({ kind: 'add-site', clientId: client.id })}
                                                className="ir-flex ir-items-center ir-gap-1.5 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-left ir-text-xs ir-font-medium ir-text-muted-foreground ir-transition-colors hover:ir-bg-muted/60 hover:ir-text-foreground disabled:ir-cursor-not-allowed disabled:ir-opacity-50 disabled:hover:ir-bg-transparent"
                                            >
                                                <Plus className="ir-size-3.5" />
                                                Añadir sitio
                                            </button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}

                        {filteredClients.length === 0 && (
                            <li className="ir-rounded-lg ir-border ir-border-dashed ir-p-4 ir-text-center ir-text-xs ir-text-muted-foreground">
                                {clients.length === 0 ? 'Aún no hay clientes. Crea el primero arriba.' : 'Ningún resultado.'}
                            </li>
                        )}
                    </ul>
                </div>
            </aside>

            {/* Right: selected site detail + its data sources */}
            {selectedSite !== null ? (
                <section className="ir-self-start ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-sm">
                    <header className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3 ir-border-b ir-px-5 ir-py-4">
                        <div className="ir-flex ir-min-w-0 ir-items-center ir-gap-3">
                            <span className="ir-flex ir-size-10 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-accent/10 ir-text-accent">
                                <Globe className="ir-size-5" />
                            </span>
                            <div className="ir-min-w-0">
                                <h2 className="ir-truncate ir-text-base ir-font-semibold ir-tracking-tight">{selectedSite.name}</h2>
                                {selectedClient !== null && <p className="ir-text-xs ir-text-muted-foreground">{selectedClient.name}</p>}
                            </div>
                        </div>
                        <div className="ir-flex ir-items-center ir-gap-2">
                            <Button variant="ghost" size="sm" onClick={() => setModal({ kind: 'edit-site', site: selectedSite })}>
                                <Pencil className="ir-size-3.5" />
                                Editar sitio
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={deleteSite.isPending}
                                onClick={() => removeSite(selectedSite)}
                                className="ir-text-danger hover:ir-bg-danger/10"
                            >
                                <Trash2 className="ir-size-3.5" />
                                Eliminar
                            </Button>
                        </div>
                    </header>

                    <div className="ir-flex ir-flex-col ir-gap-5 ir-px-5 ir-py-4">
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                            <a
                                href={selectedSite.url}
                                target="_blank"
                                rel="noreferrer"
                                className="ir-inline-flex ir-max-w-full ir-items-center ir-gap-1.5 ir-rounded-full ir-border ir-bg-muted/40 ir-px-2.5 ir-py-1 ir-text-xs ir-text-foreground/80 ir-transition hover:ir-bg-muted hover:ir-text-foreground"
                            >
                                <span className="ir-truncate">{selectedSite.url.replace(/^https?:\/\//, '')}</span>
                                <ExternalLink className="ir-size-3 ir-shrink-0" />
                            </a>
                            <Badge tone={selectedSite.status === 'active' ? 'success' : 'neutral'}>{selectedSite.status}</Badge>
                            <Badge tone="neutral">{selectedSite.currency}</Badge>
                            {selectedSite.plan_hours != null && <Badge tone="info">{selectedSite.plan_hours} h/mes</Badge>}
                        </div>

                        <div className="ir-border-t ir-pt-5">
                            <SiteDataSources siteId={selectedSite.id} />
                        </div>
                    </div>
                </section>
            ) : (
                <section className="ir-self-start ir-rounded-xl ir-border ir-border-dashed ir-bg-card">
                    <div className="ir-flex ir-flex-col ir-items-center ir-gap-3 ir-px-6 ir-py-20 ir-text-center">
                        <span className="ir-flex ir-size-12 ir-items-center ir-justify-center ir-rounded-full ir-bg-muted ir-text-muted-foreground">
                            <Building2 className="ir-size-6" />
                        </span>
                        <div>
                            <p className="ir-text-sm ir-font-medium ir-text-foreground">Selecciona un sitio</p>
                            <p className="ir-mt-1 ir-max-w-xs ir-text-xs ir-text-muted-foreground">
                                Elige un sitio en la lista de la izquierda para ver y configurar sus fuentes de datos, o crea un cliente nuevo.
                            </p>
                        </div>
                        <Button onClick={() => setModal({ kind: 'new-client' })}>
                            <Plus className="ir-size-4" />
                            Nuevo cliente
                        </Button>
                    </div>
                </section>
            )}
            </div>

            {/* Modals */}
            {modal?.kind === 'new-client' && (
                <NewClientModal
                    onClose={() => setModal(null)}
                    onCreatedSite={(siteId) => selectSite(siteId)}
                    onCreatedClient={(clientId) => setExpanded((current) => new Set(current).add(clientId))}
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
