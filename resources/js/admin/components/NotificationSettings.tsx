import { Bell, Mail, Search } from 'lucide-react';
import { type ReactElement, useMemo, useState } from 'react';

import { cn } from '@shared/lib/utils';

import { useClients, useNotificationSettings, useSites, useUpdateNotificationSettings } from '../api';
import type { NotificationSettings as Settings } from '../types';
import { Card, Input, Segmented } from './ui';

function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (next: boolean) => void; label: string; disabled?: boolean }): ReactElement {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'ir-relative ir-inline-flex ir-h-5 ir-w-9 ir-shrink-0 ir-items-center ir-rounded-full ir-transition-colors disabled:ir-opacity-50',
                checked ? 'ir-bg-primary' : 'ir-bg-muted-foreground/30',
            )}
        >
            <span className={cn('ir-inline-block ir-size-4 ir-rounded-full ir-bg-white ir-shadow ir-transition-transform', checked ? 'ir-translate-x-[18px]' : 'ir-translate-x-0.5')} />
        </button>
    );
}

/**
 * «Ajustes → Avisos»: what reaches this person about the clients' connections — in the panel's
 * bell and/or by email — and from which sites. Each change saves on its own.
 */
export function NotificationSettingsSection(): ReactElement {
    const { data: settings, isLoading } = useNotificationSettings();
    const update = useUpdateNotificationSettings();
    const { data: sites = [] } = useSites();
    const { data: clients = [] } = useClients();
    const [query, setQuery] = useState('');

    const clientName = useMemo(() => new Map(clients.map((client) => [client.id, client.name])), [clients]);
    const visibleSites = useMemo(() => {
        const term = query.trim().toLowerCase();
        const list = [...sites].sort((a, b) => a.name.localeCompare(b.name));

        return term === '' ? list : list.filter((site) => site.name.toLowerCase().includes(term) || (clientName.get(site.client_id) ?? '').toLowerCase().includes(term));
    }, [sites, query, clientName]);

    if (isLoading || settings === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando tus avisos…</p>;
    }

    const save = (next: Partial<Pick<Settings, 'sites'>> & { event?: { key: string; app?: boolean; email?: boolean } }): void => {
        update.mutate({
            events: next.event !== undefined ? { [next.event.key]: { app: next.event.app, email: next.event.email } } : undefined,
            sites: next.sites !== undefined ? next.sites : settings.sites,
        });
    };

    const chosen = settings.sites;
    const toggleSite = (id: number): void => {
        const current = chosen ?? [];
        save({ sites: current.includes(id) ? current.filter((item) => item !== id) : [...current, id] });
    };

    return (
        <>
            <Card
                title="Qué te avisamos"
                description={`Tú eliges qué ves en la campana del panel y qué te llega también a ${settings.email}. Nunca te avisamos de lo que haces tú.`}
            >
                <div className="ir-flex ir-flex-col ir-divide-y">
                    <div className="ir-hidden ir-items-center ir-gap-4 ir-pb-2 ir-text-[11px] ir-font-medium ir-uppercase ir-tracking-wider ir-text-muted-foreground sm:ir-flex">
                        <span className="ir-flex-1">Aviso</span>
                        <span className="ir-inline-flex ir-w-20 ir-items-center ir-justify-center ir-gap-1">
                            <Bell className="ir-size-3" /> Panel
                        </span>
                        <span className="ir-inline-flex ir-w-20 ir-items-center ir-justify-center ir-gap-1">
                            <Mail className="ir-size-3" /> Correo
                        </span>
                    </div>
                    {settings.events.map((event) => (
                        <div key={event.key} className="ir-flex ir-flex-col ir-gap-2 ir-py-3 sm:ir-flex-row sm:ir-items-center sm:ir-gap-4">
                            <div className="ir-min-w-0 ir-flex-1">
                                <p className="ir-text-sm ir-font-medium">{event.label}</p>
                                <p className="ir-text-xs ir-leading-relaxed ir-text-muted-foreground">{event.help}</p>
                            </div>
                            <div className="ir-flex ir-items-center ir-gap-4">
                                <label className="ir-flex ir-w-20 ir-items-center ir-gap-2 sm:ir-justify-center">
                                    <Toggle checked={event.app} label={`${event.label}: en el panel`} disabled={update.isPending} onChange={(app) => save({ event: { key: event.key, app } })} />
                                    <span className="ir-text-xs ir-text-muted-foreground sm:ir-hidden">Panel</span>
                                </label>
                                <label className="ir-flex ir-w-20 ir-items-center ir-gap-2 sm:ir-justify-center">
                                    <Toggle checked={event.email} label={`${event.label}: por correo`} disabled={update.isPending} onChange={(email) => save({ event: { key: event.key, email } })} />
                                    <span className="ir-text-xs ir-text-muted-foreground sm:ir-hidden">Correo</span>
                                </label>
                            </div>
                        </div>
                    ))}
                </div>
            </Card>

            <Card
                title="De qué sitios"
                description="Si llevas solo algunos clientes, elige sus sitios y no te llegará nada de los demás."
                actions={
                    <Segmented
                        value={chosen === null ? 'all' : 'some'}
                        onChange={(value) => save({ sites: value === 'all' ? null : [] })}
                        items={[
                            { value: 'all', label: 'Todos los sitios' },
                            { value: 'some', label: 'Solo algunos' },
                        ]}
                    />
                }
            >
                {chosen === null ? (
                    <p className="ir-text-sm ir-text-muted-foreground">
                        Te avisamos de los {sites.length} sitios de la cuenta, y de los que se agreguen después.
                    </p>
                ) : (
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                            {sites.length > 8 && (
                                <div className="ir-relative ir-min-w-0 ir-flex-1">
                                    <Search className="ir-pointer-events-none ir-absolute ir-left-2.5 ir-top-1/2 ir-size-3.5 -ir-translate-y-1/2 ir-text-muted-foreground" />
                                    <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar sitio o cliente…" className="ir-h-8 ir-pl-8 ir-text-sm" />
                                </div>
                            )}
                            <button type="button" onClick={() => save({ sites: sites.map((site) => site.id) })} className="ir-rounded-md ir-px-2 ir-py-1 ir-text-xs ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                                Marcar todos
                            </button>
                            <button type="button" onClick={() => save({ sites: [] })} className="ir-rounded-md ir-px-2 ir-py-1 ir-text-xs ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                                Ninguno
                            </button>
                        </div>
                        {chosen.length === 0 && (
                            <p className="ir-rounded-md ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-xs ir-text-foreground">
                                Elige al menos un sitio: mientras no haya ninguno marcado no te llegará ningún aviso.
                            </p>
                        )}
                        <div className="ir-grid ir-max-h-80 ir-gap-1 ir-overflow-y-auto sm:ir-grid-cols-2">
                            {visibleSites.map((site) => {
                                const on = chosen.includes(site.id);

                                return (
                                    <label
                                        key={site.id}
                                        className={cn(
                                            'ir-flex ir-cursor-pointer ir-items-center ir-gap-2.5 ir-rounded-md ir-border ir-px-3 ir-py-2 ir-transition-colors',
                                            on ? 'ir-border-primary/40 ir-bg-primary/5' : 'hover:ir-bg-muted/50',
                                        )}
                                    >
                                        <input type="checkbox" checked={on} disabled={update.isPending} onChange={() => toggleSite(site.id)} />
                                        <span className="ir-min-w-0">
                                            <span className="ir-block ir-truncate ir-text-sm ir-font-medium">{site.name}</span>
                                            <span className="ir-block ir-truncate ir-text-xs ir-text-muted-foreground">{clientName.get(site.client_id) ?? ''}</span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>
                )}
                {update.isError && <p className="ir-mt-3 ir-text-xs ir-text-danger">No se pudo guardar. Inténtalo de nuevo.</p>}
            </Card>
        </>
    );
}
