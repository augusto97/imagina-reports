import { BarChart3, Check, Hash, Layers3, Search, Table2 } from 'lucide-react';
import { type ReactElement, useEffect, useMemo, useRef, useState } from 'react';

import { Button, Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { BLOCK_METRIC_TYPES, GEO_DIMENSIONS } from './blockFactory';

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
    calc: 'Métricas calculadas',
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
 * The data picker.
 *
 * Choosing a source is the FIRST decision — "where does this number come from?" — so it is
 * an explicit, always-visible column here rather than something implied by group headings.
 * (The first version of this dialog only grouped by source and offered no way to narrow to
 * one, which left people unable to tell which source they were even looking at.)
 *
 * Everything else is one list: type to search across all sources, each entry labelled with
 * what it produces. "Modelable" is called out because those are the only ones that can be
 * filtered — the thing nobody could work out from the old UI.
 */
export function MetricPicker({
    catalog: allEntries,
    blockType,
    value,
    onPick,
    onClose,
}: {
    catalog: CatalogEntry[];
    /** Restricts the list to what this block can actually draw. */
    blockType: string;
    value: { source: string; metric: string } | null;
    onPick: (entry: CatalogEntry) => void;
    onClose: () => void;
}): ReactElement {
    // Only offer what the block can render. A map used to list campaign names and a KPI
    // page tables — bindings guaranteed to come back empty.
    const catalog = useMemo(() => {
        const allowed = BLOCK_METRIC_TYPES[blockType as keyof typeof BLOCK_METRIC_TYPES];

        return allEntries.filter((entry) => {
            if (allowed !== undefined && !allowed.includes(entry.type)) {
                return false;
            }
            // A map needs somewhere to put the pins.
            if (blockType === 'geo_map') {
                return entry.dimensions.some((dimension) => GEO_DIMENSIONS.includes(dimension));
            }

            return true;
        });
    }, [allEntries, blockType]);

    const [query, setQuery] = useState('');
    // Start on the bound metric's source, so reopening lands where you left off.
    const [source, setSource] = useState<string>(value?.source ?? '');
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        inputRef.current?.focus();
        const onKey = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    /** Every source in the catalog with how many metrics it offers. */
    const sources = useMemo(() => {
        const counts = new Map<string, number>();
        for (const entry of catalog) {
            counts.set(entry.source, (counts.get(entry.source) ?? 0) + 1);
        }

        return [...counts.entries()].sort((a, b) => sourceLabel(a[0]).localeCompare(sourceLabel(b[0])));
    }, [catalog]);

    const visible = useMemo(() => {
        const term = query.trim().toLowerCase();

        return catalog.filter((entry) => {
            if (source !== '' && entry.source !== source) return false;
            if (term === '') return true;

            return (
                entry.label.toLowerCase().includes(term) ||
                entry.metric.toLowerCase().includes(term) ||
                sourceLabel(entry.source).toLowerCase().includes(term)
            );
        });
    }, [catalog, query, source]);

    /** Only group when showing several sources — inside one source it's just noise. */
    const groups = useMemo(() => {
        const bySource = new Map<string, CatalogEntry[]>();
        for (const entry of visible) {
            const list = bySource.get(entry.source) ?? [];
            list.push(entry);
            bySource.set(entry.source, list);
        }

        return [...bySource.entries()].sort((a, b) => sourceLabel(a[0]).localeCompare(sourceLabel(b[0])));
    }, [visible]);

    const sourceButton = (key: string, label: string, count: number): ReactElement => {
        const active = source === key;

        return (
            <button
                key={key === '' ? '__all' : key}
                type="button"
                onClick={() => setSource(key)}
                className={`ir-flex ir-w-full ir-shrink-0 ir-items-center ir-justify-between ir-gap-2 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-left ir-text-xs ir-transition ${
                    active ? 'ir-bg-primary/10 ir-font-medium ir-text-primary' : 'ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground'
                }`}
            >
                <span className="ir-truncate">{label}</span>
                <span className="ir-shrink-0 ir-tabular-nums ir-text-[10px] ir-opacity-70">{count}</span>
            </button>
        );
    };

    return (
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-start ir-justify-center ir-bg-black/40 ir-p-4 ir-pt-[8vh]">
            <button type="button" aria-label="Cerrar" onClick={onClose} className="ir-fixed ir-inset-0 ir-cursor-default" />

            <div className="ir-relative ir-z-10 ir-flex ir-max-h-[72vh] ir-w-full ir-max-w-2xl ir-flex-col ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-lg">
                <div className="ir-flex ir-items-center ir-gap-2 ir-border-b ir-px-3 ir-py-2.5">
                    <Search className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                    <Input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Busca un dato: visitas, inversión, campañas…"
                        className="ir-h-8 ir-border-0 ir-bg-transparent ir-px-0 ir-shadow-none focus:ir-ring-0"
                    />
                </div>

                <div className="ir-flex ir-min-h-0 ir-flex-1 ir-flex-col sm:ir-flex-row">
                    {/* Source column — the first decision, always on screen. Becomes a
                        horizontal strip on narrow viewports rather than disappearing. */}
                    <div className="ir-flex ir-shrink-0 ir-gap-1 ir-overflow-x-auto ir-border-b ir-p-2 sm:ir-w-48 sm:ir-flex-col sm:ir-overflow-y-auto sm:ir-border-b-0 sm:ir-border-r">
                        <p className="ir-hidden ir-px-2 ir-pb-1 ir-text-[10px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground/70 sm:ir-block">
                            Fuente
                        </p>
                        <div className="ir-flex ir-gap-1 sm:ir-w-full sm:ir-flex-col">
                            {sourceButton('', 'Todas las fuentes', catalog.length)}
                            {sources.map(([key, count]) => sourceButton(key, sourceLabel(key), count))}
                        </div>
                    </div>

                    <div className="ir-min-h-0 ir-flex-1 ir-overflow-y-auto ir-p-2">
                        {visible.length === 0 ? (
                            <div className="ir-px-3 ir-py-10 ir-text-center">
                                <p className="ir-text-sm ir-text-muted-foreground">
                                    {allEntries.length === 0
                                        ? 'Este sitio aún no tiene datos. Conecta una fuente y sincroniza un periodo.'
                                        : catalog.length === 0
                                          ? 'Ninguno de los datos disponibles se puede representar en este bloque.'
                                          : 'Ningún dato coincide. Prueba con «Todas las fuentes».'}
                                </p>
                            </div>
                        ) : (
                            groups.map(([groupSource, entries]) => (
                                <div key={groupSource} className="ir-mb-2 last:ir-mb-0">
                                    {source === '' && (
                                        <p className="ir-px-2 ir-py-1 ir-text-[10px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground/70">
                                            {sourceLabel(groupSource)}
                                        </p>
                                    )}
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
                                                        {sourceLabel(entry.source)} · {meta?.label}
                                                        {entry.type === 'dataset' && ' · se puede filtrar'}
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
