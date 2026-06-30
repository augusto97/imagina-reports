import { CalendarRange, RefreshCw } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { RANGE_PRESETS } from '@shared/lib/dateRanges';
import { useSyncSiteById } from '../api';
import { Button } from './ui';

/**
 * Sync an ARBITRARY period for a site on demand (CLAUDE.md §3.1). When a client asks for a
 * specific window — a month, a quarter, or an exact from→to range — the agency syncs it here:
 * the connectors aggregate THAT window at source (§3.3), storing a snapshot for the complete
 * period. It then becomes selectable in the live dashboard and usable for a report over that
 * range. This is the accurate way to offer custom ranges (vs. lossily merging snapshots).
 */
export function RangeSyncMenu({ siteId }: { siteId: number }): ReactElement {
    const sync = useSyncSiteById();
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');

    const applyPreset = (key: string): void => {
        const preset = RANGE_PRESETS.find((entry) => entry.key === key);
        if (preset !== undefined) {
            const range = preset.range();
            setFrom(range.start);
            setTo(range.end);
        }
    };

    const valid = from !== '' && to !== '' && from <= to;
    const busy = sync.isPending;

    const run = (): void => {
        if (valid) {
            sync.mutate({ siteId, period_start: from, period_end: to });
        }
    };

    const input = 'ir-h-9 ir-rounded-md ir-border ir-bg-card ir-px-2 ir-text-sm';

    return (
        <div className="ir-rounded-md ir-border ir-bg-muted/20 ir-p-3">
            <p className="ir-mb-1 ir-flex ir-items-center ir-gap-1.5 ir-text-sm ir-font-semibold ir-tracking-tight">
                <CalendarRange className="ir-size-4 ir-text-primary" />
                Sincronizar un periodo
            </p>
            <p className="ir-mb-3 ir-text-[11px] ir-text-muted-foreground">
                Si un cliente pide un rango concreto (un mes, un trimestre, o del X al Y), sincronízalo aquí: se guarda el snapshot de ese periodo completo y queda disponible para generar el reporte y para que el cliente lo elija en el panel en vivo.
            </p>
            <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                <select className={input} value="" onChange={(event) => applyPreset(event.target.value)}>
                    <option value="">Rango rápido…</option>
                    {RANGE_PRESETS.map((preset) => (
                        <option key={preset.key} value={preset.key}>
                            {preset.label}
                        </option>
                    ))}
                </select>
                <input type="date" className={input} value={from} max={to !== '' ? to : undefined} onChange={(event) => setFrom(event.target.value)} aria-label="Desde" />
                <span className="ir-text-muted-foreground">→</span>
                <input type="date" className={input} value={to} min={from !== '' ? from : undefined} onChange={(event) => setTo(event.target.value)} aria-label="Hasta" />
                <Button size="sm" onClick={run} disabled={!valid || busy}>
                    <RefreshCw className={`ir-size-3.5 ${busy ? 'ir-animate-spin' : ''}`} />
                    {busy ? 'Encolando…' : 'Sincronizar'}
                </Button>
            </div>
            {sync.isSuccess && (
                <p className="ir-mt-2 ir-text-xs ir-text-emerald-600">
                    Encolado: {sync.data?.queued ?? 0} fuente(s). En unos segundos tendrás el periodo disponible.
                </p>
            )}
            {sync.isError && <p className="ir-mt-2 ir-text-xs ir-text-red-500">No se pudo encolar la sincronización.</p>}
        </div>
    );
}
