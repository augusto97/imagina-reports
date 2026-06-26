import { useEffect, useRef, useState, type ReactElement } from 'react';
import { Check, ChevronDown, Clock, RefreshCw, X } from 'lucide-react';
import { cn } from '@shared/lib/utils';
import { Button } from '../components/ui';
import { useConnectors, useSiteDataSources, useSyncSite } from '../api';
import type { DataSourceDto } from '../types';

const SYNC_TIMEOUT_MS = 45000;

/** Spanish relative time for a last-synced timestamp. */
function timeAgo(iso: string | null): string {
    if (iso === null) {
        return 'sin sincronizar';
    }

    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) {
        return '—';
    }

    const seconds = Math.max(0, Math.round((Date.now() - then) / 1000));
    if (seconds < 45) {
        return 'hace un momento';
    }

    const minutes = Math.round(seconds / 60);
    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    const hours = Math.round(minutes / 60);
    if (hours < 24) {
        return `hace ${hours} h`;
    }

    const days = Math.round(hours / 24);
    if (days === 1) {
        return 'ayer';
    }
    if (days < 30) {
        return `hace ${days} d`;
    }

    return new Date(iso).toLocaleDateString('es');
}

interface SyncStatusProps {
    siteId: number | null;
    period: { period_start: string; period_end: string };
    /** Human label of the previewed period, e.g. "junio 2026". */
    monthLabel: string;
    /** Called once a sync run finishes (so the editor can refresh its preview). */
    onSynced?: () => void;
}

/**
 * The "Sincronizar" control with a status panel: shows every data source of the
 * site, when each last synced, and live per-source progress while a sync runs.
 * Completion is detected by each source's `last_synced_at` advancing past the value
 * captured when the sync was triggered (robust to clock skew), since SyncService
 * always stamps it — ok or error.
 */
export function SyncStatus({ siteId, period, monthLabel, onSynced }: SyncStatusProps): ReactElement {
    const [open, setOpen] = useState(false);
    // Map of source id → last_synced_at captured at trigger time; null when idle.
    const [baseline, setBaseline] = useState<Record<number, string | null> | null>(null);
    const startedAt = useRef(0);
    const wrapRef = useRef<HTMLDivElement>(null);

    const syncing = baseline !== null;
    const sync = useSyncSite(siteId ?? 0);
    const { data: sources = [] } = useSiteDataSources(siteId, { refetchInterval: syncing ? 2000 : false });
    const { data: connectors = [] } = useConnectors();

    const label = (type: string): string => connectors.find((connector) => connector.key === type)?.label ?? type;
    const isDone = (source: DataSourceDto): boolean =>
        baseline !== null && source.last_synced_at !== (baseline[source.id] ?? null);

    // Close the panel on an outside click.
    useEffect(() => {
        if (!open) {
            return;
        }
        const onPointerDown = (event: MouseEvent): void => {
            if (wrapRef.current !== null && !wrapRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    // Finish the run once every tracked source has advanced, or after a timeout.
    useEffect(() => {
        if (baseline === null) {
            return;
        }
        const ids = Object.keys(baseline).map(Number);
        const tracked = sources.filter((source) => ids.includes(source.id));
        const allDone = tracked.length > 0 && tracked.every((source) => source.last_synced_at !== (baseline[source.id] ?? null));

        if (allDone || Date.now() - startedAt.current > SYNC_TIMEOUT_MS) {
            setBaseline(null);
            onSynced?.();
        }
    }, [sources, baseline, onSynced]);

    // Sync all sources, or only the given ids (e.g. a single newly-added source).
    const start = (ids?: number[]): void => {
        if (siteId === null) {
            return;
        }
        const tracked = ids === undefined ? sources : sources.filter((source) => ids.includes(source.id));
        const snapshot: Record<number, string | null> = {};
        for (const source of tracked) {
            snapshot[source.id] = source.last_synced_at;
        }
        startedAt.current = Date.now();
        setBaseline(snapshot);
        setOpen(true);
        sync.mutate(ids === undefined ? period : { ...period, data_source_ids: ids });
    };

    // Count progress over the TRACKED sources only (a single-source sync tracks just one).
    const trackedCount = baseline !== null ? Object.keys(baseline).length : sources.length;
    const doneCount = baseline !== null ? sources.filter((source) => baseline[source.id] !== undefined && isDone(source)).length : 0;
    const okCount = sources.filter((source) => source.status === 'ok').length;
    const errorCount = sources.filter((source) => source.status === 'error').length;

    return (
        <div className="ir-relative" ref={wrapRef}>
            <div className="ir-flex ir-items-center">
                <Button variant="ghost" onClick={() => start()} disabled={siteId === null || syncing}>
                    <RefreshCw className={cn('ir-size-4', syncing && 'ir-animate-spin')} />
                    {syncing ? `Sincronizando… ${doneCount}/${trackedCount}` : 'Sincronizar'}
                </Button>
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    disabled={siteId === null}
                    title="Estado de sincronización"
                    className="ir-flex ir-items-center ir-rounded ir-px-1 ir-py-2 hover:ir-bg-muted disabled:ir-opacity-40"
                >
                    {!syncing && sources.length > 0 && (
                        <span className={cn('ir-mr-1 ir-size-2 ir-rounded-full', errorCount > 0 ? 'ir-bg-red-500' : 'ir-bg-emerald-500')} />
                    )}
                    <ChevronDown className="ir-size-3.5 ir-text-muted-foreground" />
                </button>
            </div>

            {open && (
                <div className="ir-absolute ir-right-0 ir-top-full ir-z-50 ir-mt-1 ir-w-80 ir-rounded-lg ir-border ir-bg-card ir-p-3 ir-shadow-lg">
                    <div className="ir-mb-2 ir-flex ir-items-center ir-justify-between">
                        <span className="ir-text-sm ir-font-semibold">Sincronización</span>
                        <span className="ir-text-xs ir-text-muted-foreground">{monthLabel}</span>
                    </div>

                    {siteId === null ? (
                        <p className="ir-text-xs ir-text-muted-foreground">Selecciona un sitio para sincronizar sus fuentes.</p>
                    ) : sources.length === 0 ? (
                        <p className="ir-text-xs ir-text-muted-foreground">Este sitio no tiene fuentes de datos configuradas.</p>
                    ) : (
                        <ul className="ir-space-y-1.5">
                            {sources.map((source) => {
                                const busy = syncing && !isDone(source);
                                return (
                                    <li key={source.id} className="ir-flex ir-items-start ir-gap-2 ir-rounded ir-px-1 ir-py-1">
                                        <span className="ir-mt-0.5 ir-shrink-0">
                                            {busy ? (
                                                <RefreshCw className="ir-size-3.5 ir-animate-spin ir-text-muted-foreground" />
                                            ) : source.status === 'error' ? (
                                                <X className="ir-size-3.5 ir-text-red-500" />
                                            ) : source.status === 'ok' ? (
                                                <Check className="ir-size-3.5 ir-text-emerald-500" />
                                            ) : (
                                                <Clock className="ir-size-3.5 ir-text-muted-foreground" />
                                            )}
                                        </span>
                                        <div className="ir-min-w-0 ir-flex-1">
                                            <div className="ir-flex ir-items-center ir-justify-between ir-gap-2">
                                                <span className="ir-truncate ir-text-sm ir-font-medium">{label(source.type)}</span>
                                                <span className="ir-flex ir-shrink-0 ir-items-center ir-gap-1 ir-text-[11px] ir-text-muted-foreground">
                                                    {busy ? 'sincronizando…' : timeAgo(source.last_synced_at)}
                                                    {!syncing && (
                                                        <button
                                                            type="button"
                                                            title="Sincronizar solo esta fuente"
                                                            onClick={() => start([source.id])}
                                                            className="ir-rounded ir-p-0.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                                                        >
                                                            <RefreshCw className="ir-size-3" />
                                                        </button>
                                                    )}
                                                </span>
                                            </div>
                                            {source.status === 'error' && source.last_error !== null && !busy && (
                                                <p className="ir-mt-0.5 ir-text-[11px] ir-text-red-600">{source.last_error}</p>
                                            )}
                                            {source.type === 'mainwp' && source.child_reports_active === false && !busy && (
                                                <p className="ir-mt-0.5 ir-text-[11px] ir-text-amber-600">
                                                    Instala «MainWP Child Reports» en el sitio para registrar el historial de actualizaciones.
                                                </p>
                                            )}
                                            {source.type === 'mainwp' && source.child_reports_active === true && !busy && (
                                                <p className="ir-mt-0.5 ir-text-[11px] ir-text-emerald-600">Child Reports activo · registrando historial.</p>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {sources.length > 0 && (
                        <div className="ir-mt-2.5 ir-flex ir-items-center ir-justify-between ir-border-t ir-pt-2">
                            <span className="ir-text-[11px] ir-text-muted-foreground">
                                {errorCount > 0 ? `${okCount} ok · ${errorCount} con error` : `${okCount}/${sources.length} al día`}
                            </span>
                            <Button
                                variant="ghost"
                                onClick={() => start()}
                                disabled={siteId === null || syncing}
                                className="ir-h-7 ir-px-2 ir-text-xs"
                            >
                                <RefreshCw className={cn('ir-size-3.5', syncing && 'ir-animate-spin')} />
                                Sincronizar ahora
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
