import { BarChart3, Check, Hash, Search, Table2, Layers3 } from 'lucide-react';
import { type ReactElement, useEffect, useMemo, useRef, useState } from 'react';

import { Button, Input } from '../components/ui';
import type { CatalogEntry } from '../types';

/** Human names for the raw source keys the catalog carries (`ga4`, `facebook_ads`…). */
const SOURCE_LABELS: Record<string, string> = {
    ga4: 'Google Analytics',
    gsc: 'Search Console',
    google_ads: 'Google Ads',
    facebook_ads: 'Facebook Ads',
    instagram: 'Instagram',
    tiktok_ads: 'TikTok Ads',
    mailchimp: 'Mailchimp',
    woocommerce: 'WooCommerce',
    mainwp: 'Mantenimiento',
    cloudflare: 'Cloudflare',
    crowdsec: 'CrowdSec',
    virusdie: 'VirusDie',
    betteruptime: 'Disponibilidad',
    site_agent: 'Agente del sitio',
    database: 'Base de datos',
    calc: 'Calculadas',
    worklog: 'Trabajo realizado',
};

export function sourceLabel(source: string): string {
    return SOURCE_LABELS[source] ?? source;
}

/** What each metric shape means, in the user's words rather than the engine's. */
const TYPE_META: Record<string, { label: string; icon: typeof Hash }> = {
    scalar: { label: 'Número', icon: Hash },
    number: { label: 'Número', icon: Hash },
    series: { label: 'Evolución', icon: BarChart3 },
    table: { label: 'Tabla', icon: Table2 },
    dataset: { label: 'Modelable', icon: Layers3 },
};

/**
 * The data picker: one dialog that replaces the four stacked selects the inspector used to
 * show (source chip, source filter, search box, metric select — three of which only appeared
 * under certain conditions, so the panel changed shape depending on the data).
 *
 * Everything is always in the same place: type to search, metrics grouped under the human
 * name of their source, each labelled with what it produces. "Modelable" is called out
 * because those are the only ones that can be filtered — the thing people could not work out.
 */
export function MetricPicker({
    catalog,
    value,
    onPick,
    onClose,
}: {
    catalog: CatalogEntry[];
    value: { source: string; metric: string } | null;
    onPick: (entry: CatalogEntry) => void;
    onClose: () => void;
}): ReactElement {
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        inputRef.current?.focus();
        const onKey = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const groups = useMemo(() => {
        const term = query.trim().toLowerCase();
        const matches = catalog.filter((entry) => {
            if (term === '') return true;

            return (
                entry.label.toLowerCase().includes(term) ||
                entry.metric.toLowerCase().includes(term) ||
                sourceLabel(entry.source).toLowerCase().includes(term)
            );
        });

        const bySource = new Map<string, CatalogEntry[]>();
        for (const entry of matches) {
            const list = bySource.get(entry.source) ?? [];
            list.push(entry);
            bySource.set(entry.source, list);
        }

        return [...bySource.entries()].sort((a, b) => sourceLabel(a[0]).localeCompare(sourceLabel(b[0])));
    }, [catalog, query]);

    const total = groups.reduce((sum, [, entries]) => sum + entries.length, 0);

    return (
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-start ir-justify-center ir-bg-black/40 ir-p-4 ir-pt-[8vh]">
            <button type="button" aria-label="Cerrar" onClick={onClose} className="ir-fixed ir-inset-0 ir-cursor-default" />

            <div className="ir-relative ir-z-10 ir-flex ir-max-h-[70vh] ir-w-full ir-max-w-xl ir-flex-col ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-lg">
                <div className="ir-flex ir-items-center ir-gap-2 ir-border-b ir-px-3 ir-py-2.5">
                    <Search className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                    <Input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Busca un dato: visitas, inversión, campañas…"
                        className="ir-h-8 ir-border-0 ir-bg-transparent ir-px-0 ir-shadow-none focus:ir-ring-0"
                    />
                    <span className="ir-shrink-0 ir-text-[11px] ir-text-muted-foreground">{total}</span>
                </div>

                <div className="ir-min-h-0 ir-flex-1 ir-overflow-y-auto ir-p-2">
                    {total === 0 ? (
                        <div className="ir-px-3 ir-py-10 ir-text-center">
                            <p className="ir-text-sm ir-text-muted-foreground">
                                {catalog.length === 0
                                    ? 'Este sitio aún no tiene datos. Conecta una fuente y sincroniza un periodo.'
                                    : 'Ningún dato coincide con la búsqueda.'}
                            </p>
                        </div>
                    ) : (
                        groups.map(([source, entries]) => (
                            <div key={source} className="ir-mb-2 last:ir-mb-0">
                                <p className="ir-px-2 ir-py-1 ir-text-[10px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground/70">
                                    {sourceLabel(source)}
                                </p>
                                {entries.map((entry) => {
                                    const meta = TYPE_META[entry.type] ?? TYPE_META.scalar;
                                    const TypeIcon = meta?.icon ?? Hash;
                                    const selected = value?.source === entry.source && value.metric === entry.metric;

                                    return (
                                        <button
                                            key={entry.key}
                                            type="button"
                                            onClick={() => {
                                                onPick(entry);
                                                onClose();
                                            }}
                                            className={`ir-flex ir-w-full ir-items-center ir-gap-2.5 ir-rounded-md ir-px-2 ir-py-2 ir-text-left ir-transition ${
                                                selected ? 'ir-bg-primary/10' : 'hover:ir-bg-muted'
                                            }`}
                                        >
                                            <span className="ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                                                <TypeIcon className="ir-size-3.5" />
                                            </span>
                                            <span className="ir-min-w-0 ir-flex-1">
                                                <span className="ir-block ir-truncate ir-text-sm ir-font-medium">{entry.label}</span>
                                                <span className="ir-block ir-truncate ir-text-[11px] ir-text-muted-foreground">
                                                    {meta?.label}
                                                    {entry.type === 'dataset' && ' · se puede filtrar y desglosar'}
                                                </span>
                                            </span>
                                            {selected && <Check className="ir-size-4 ir-shrink-0 ir-text-primary" />}
                                        </button>
                                    );
                                })}
                            </div>
                        ))
                    )}
                </div>

                <div className="ir-flex ir-items-center ir-justify-between ir-gap-3 ir-border-t ir-px-3 ir-py-2">
                    <p className="ir-text-[11px] ir-text-muted-foreground">
                        Los datos <strong>modelables</strong> permiten filtrar (p. ej. solo unas campañas) y desglosar.
                    </p>
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cerrar
                    </Button>
                </div>
            </div>
        </div>
    );
}
