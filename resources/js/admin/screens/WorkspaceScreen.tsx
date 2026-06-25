import { type FormEvent, type ReactElement, useMemo, useState } from 'react';

import {
    useClients,
    useCreateClient,
    useCreateSite,
    useDeleteClient,
    useSites,
    useUpdateClient,
    useUpdateSite,
} from '../api';
import { SiteDataSources } from '../components/SiteDataSources';
import { Badge, Button, Card, Field, Input, Modal, Select } from '../components/ui';
import { CURRENCIES } from '../currencies';
import { useAdminUi } from '../store';
import { TIMEZONES } from '../timezones';
import type { Client, Site } from '../types';

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

/* ------------------------------- main screen ------------------------------ */

export function WorkspaceScreen(): ReactElement {
    const { data: clients = [] } = useClients();
    const { data: sites = [] } = useSites();
    const selectedSiteId = useAdminUi((state) => state.selectedSiteId);
    const selectSite = useAdminUi((state) => state.selectSite);
    const deleteClient = useDeleteClient();

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

    return (
        <div className="ir-grid ir-gap-6 lg:ir-grid-cols-[340px_1fr]">
            {/* Left: clients → sites tree */}
            <Card
                title="Clientes"
                actions={
                    <Button size="sm" onClick={() => setModal({ kind: 'new-client' })}>
                        + Nuevo cliente
                    </Button>
                }
                className="ir-self-start"
            >
                <div className="ir-flex ir-flex-col ir-gap-3">
                    <Input placeholder="Buscar cliente o sitio…" value={query} onChange={(event) => setQuery(event.target.value)} />

                    <ul className="ir-flex ir-flex-col ir-gap-0.5">
                        {filteredClients.map((client) => {
                            const clientSites = sitesByClient.get(client.id) ?? [];
                            const isOpen = effectiveExpanded.has(client.id);

                            return (
                                <li key={client.id}>
                                    <div className="ir-group ir-flex ir-items-center ir-gap-1 ir-rounded-md ir-px-2 ir-py-1.5 hover:ir-bg-muted/60">
                                        <button type="button" onClick={() => toggle(client.id)} className="ir-flex ir-min-w-0 ir-flex-1 ir-items-center ir-gap-2 ir-text-left">
                                            <span className="ir-text-xs ir-text-muted-foreground">{isOpen ? '▾' : '▸'}</span>
                                            <span className="ir-truncate ir-text-sm ir-font-medium">{client.name}</span>
                                            <span className="ir-text-xs ir-text-muted-foreground">{clientSites.length}</span>
                                        </button>
                                        <span className="ir-flex ir-shrink-0 ir-items-center ir-gap-0.5 ir-opacity-0 group-hover:ir-opacity-100">
                                            <Button variant="ghost" size="sm" onClick={() => setModal({ kind: 'edit-client', client })}>
                                                Editar
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => removeClient(client)} disabled={deleteClient.isPending}>
                                                ✕
                                            </Button>
                                        </span>
                                    </div>

                                    {isOpen && (
                                        <div className="ir-ml-4 ir-flex ir-flex-col ir-gap-0.5 ir-border-l ir-pl-2">
                                            {clientSites.map((site) => (
                                                <button
                                                    key={site.id}
                                                    type="button"
                                                    onClick={() => selectSite(site.id)}
                                                    className={
                                                        'ir-truncate ir-rounded-md ir-px-2 ir-py-1.5 ir-text-left ir-text-sm ir-transition-colors ' +
                                                        (site.id === selectedSiteId ? 'ir-bg-accent/15 ir-font-medium ir-text-accent' : 'hover:ir-bg-muted/60')
                                                    }
                                                >
                                                    {site.name}
                                                </button>
                                            ))}
                                            <Button variant="ghost" size="sm" className="ir-mt-0.5 ir-justify-start" onClick={() => setModal({ kind: 'add-site', clientId: client.id })}>
                                                + Sitio
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}

                        {filteredClients.length === 0 && (
                            <li className="ir-rounded-md ir-border ir-border-dashed ir-p-4 ir-text-center ir-text-sm ir-text-muted-foreground">
                                {clients.length === 0 ? 'Aún no hay clientes. Crea el primero.' : 'Ningún resultado.'}
                            </li>
                        )}
                    </ul>
                </div>
            </Card>

            {/* Right: selected site detail + its data sources */}
            {selectedSite !== null ? (
                <Card
                    title={selectedSite.name}
                    description={selectedClient !== null ? `Cliente: ${selectedClient.name}` : undefined}
                    actions={
                        <Button variant="ghost" size="sm" onClick={() => setModal({ kind: 'edit-site', site: selectedSite })}>
                            Editar sitio
                        </Button>
                    }
                    className="ir-self-start"
                >
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                            <a href={selectedSite.url} target="_blank" rel="noreferrer" className="ir-text-sm ir-text-primary ir-underline">
                                {selectedSite.url} ↗
                            </a>
                            <Badge tone={selectedSite.status === 'active' ? 'success' : 'neutral'}>{selectedSite.status}</Badge>
                            <Badge tone="neutral">{selectedSite.currency}</Badge>
                            {selectedSite.plan_hours != null && <Badge tone="info">{selectedSite.plan_hours} h/mes</Badge>}
                        </div>

                        <div className="ir-border-t ir-pt-4">
                            <SiteDataSources siteId={selectedSite.id} />
                        </div>
                    </div>
                </Card>
            ) : (
                <Card className="ir-self-start">
                    <div className="ir-flex ir-flex-col ir-items-center ir-gap-3 ir-py-12 ir-text-center">
                        <p className="ir-text-sm ir-text-muted-foreground">Selecciona un sitio de la izquierda para ver y configurar sus fuentes de datos.</p>
                        <Button onClick={() => setModal({ kind: 'new-client' })}>+ Nuevo cliente</Button>
                    </div>
                </Card>
            )}

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
