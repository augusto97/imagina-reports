import { AlertTriangle, ChevronDown, RefreshCw } from 'lucide-react';
import { type ReactElement, useEffect, useRef, useState } from 'react';

import { useConnectors, useDataSourceCoverage, useSiteDataSources, useSyncSiteById } from '../api';
import { coverageSpan, formatGaps } from '../lib/coverage';

/**
 * Sync control for a report row: "Sincronizar periodo" (all sources) plus a dropdown to
 * sync just one source, each showing the date span it already has stored (and any gaps) —
 * so you don't re-pull a source whose data you already have (CLAUDE.md §3.1 decoupling).
 */
export function PeriodSyncMenu({ siteId, periodStart, periodEnd }: { siteId: number; periodStart: string; periodEnd: string }): ReactElement {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef<HTMLDivElement>(null);

    const sync = useSyncSiteById();
    const { data: sources = [] } = useSiteDataSources(siteId);
    const { data: coverage = [] } = useDataSourceCoverage(siteId);
    const { data: connectors = [] } = useConnectors();

    const label = (type: string): string => connectors.find((connector) => connector.key === type)?.label ?? type;
    const coverageOf = (id: number): (typeof coverage)[number] | undefined => coverage.find((entry) => entry.data_source_id === id);

    useEffect(() => {
        if (!open) {
            return;
        }
        const onDown = (event: MouseEvent): void => {
            if (wrapRef.current !== null && !wrapRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const run = (ids?: number[]): void => {
        sync.mutate({ siteId, period_start: periodStart, period_end: periodEnd, data_source_ids: ids });
        setOpen(false);
    };

    const busy = sync.isPending && sync.variables?.siteId === siteId;

    return (
        <div className="ir-relative ir-inline-flex" ref={wrapRef}>
            <button
                type="button"
                className="ir-rounded-l-md ir-border ir-border-r-0 ir-px-2 ir-py-1 ir-text-left ir-text-xs ir-text-primary hover:ir-bg-muted disabled:ir-opacity-50"
                onClick={() => run()}
                disabled={busy}
            >
                <RefreshCw className="ir-mr-1 ir-inline ir-size-3" />
                {busy ? 'Sincronizando…' : 'Sincronizar periodo'}
            </button>
            <button
                type="button"
                title="Sincronizar una fuente concreta"
                className="ir-rounded-r-md ir-border ir-px-1 ir-py-1 ir-text-muted-foreground hover:ir-bg-muted disabled:ir-opacity-50"
                onClick={() => setOpen((value) => !value)}
                disabled={busy || sources.length === 0}
            >
                <ChevronDown className="ir-size-3" />
            </button>

            {open && sources.length > 0 && (
                <div className="ir-absolute ir-left-0 ir-top-full ir-z-50 ir-mt-1 ir-w-64 ir-rounded-lg ir-border ir-bg-card ir-p-2 ir-shadow-lg">
                    <p className="ir-mb-1 ir-px-1 ir-text-[11px] ir-font-medium ir-text-muted-foreground">Sincronizar solo una fuente</p>
                    <ul className="ir-space-y-0.5">
                        {sources.map((source) => {
                            const cov = coverageOf(source.id);
                            const span = cov !== undefined ? coverageSpan(cov.period_start, cov.period_end) : null;
                            const gaps = cov?.gaps ?? [];

                            return (
                                <li key={source.id}>
                                    <button
                                        type="button"
                                        onClick={() => run([source.id])}
                                        className="ir-flex ir-w-full ir-items-center ir-justify-between ir-gap-2 ir-rounded ir-px-1.5 ir-py-1.5 ir-text-left hover:ir-bg-muted"
                                    >
                                        <span className="ir-min-w-0">
                                            <span className="ir-block ir-truncate ir-text-xs ir-font-medium">{label(source.type)}</span>
                                            <span className="ir-block ir-text-[11px] ir-text-muted-foreground">{span === null ? 'sin datos' : `datos: ${span}`}</span>
                                            {gaps.length > 0 && (
                                                <span className="ir-mt-0.5 ir-flex ir-items-center ir-gap-1 ir-text-[11px] ir-text-amber-600">
                                                    <AlertTriangle className="ir-size-3 ir-shrink-0" />
                                                    faltan: {formatGaps(gaps, 2)}
                                                </span>
                                            )}
                                        </span>
                                        <RefreshCw className="ir-size-3 ir-shrink-0 ir-text-muted-foreground" />
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}
