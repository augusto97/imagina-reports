import { Building2, Globe } from 'lucide-react';
import { type ReactElement, useEffect, useRef, useState } from 'react';

import { type CalcMetric, useCalcPreview, useUpdateCalculatedMetrics, useUpdateSiteCalculatedMetrics } from '../api';
import { CalcMetricsEditor } from '../editor/CalcMetricsEditor';
import type { CatalogEntry } from '../types';
import { Button, Card, Modal } from './ui';

type Scope = 'agency' | 'site';

/**
 * Calculated-metrics manager (CLAUDE.md §10.1). Two scopes: AGENCY (reusable in every
 * report) and SITE (this client's own, layered on top). Each formula shows "= value" live
 * from the site's real data for the chosen period, so you confirm it before saving.
 */
export function CalcMetricsModal({
    siteId,
    catalog,
    periodStart,
    periodEnd,
    agencyMetrics,
    siteMetrics,
    onClose,
}: {
    siteId: number | null;
    catalog: CatalogEntry[];
    periodStart?: string;
    periodEnd?: string;
    agencyMetrics: CalcMetric[];
    siteMetrics: CalcMetric[];
    onClose: () => void;
}): ReactElement {
    const [scope, setScope] = useState<Scope>('agency');
    const [agency, setAgency] = useState<CalcMetric[]>(agencyMetrics);
    const [site, setSite] = useState<CalcMetric[]>(siteMetrics);
    const [values, setValues] = useState<Record<string, number>>({});

    const saveAgency = useUpdateCalculatedMetrics();
    const saveSite = useUpdateSiteCalculatedMetrics(siteId ?? 0);
    const calcPreview = useCalcPreview(siteId ?? 0);
    const runPreview = calcPreview.mutate;

    const metrics = scope === 'agency' ? agency : site;
    const setMetrics = scope === 'agency' ? setAgency : setSite;

    // Live "= value": evaluate BOTH scopes' formulas against the site's real data (debounced).
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => {
        if (siteId === null) {
            return;
        }
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => {
            runPreview(
                { calculated_metrics: [...agency, ...site], period_start: periodStart, period_end: periodEnd },
                { onSuccess: (result) => setValues(result.values) },
            );
        }, 400);

        return () => {
            if (timer.current !== null) clearTimeout(timer.current);
        };
    }, [agency, site, siteId, periodStart, periodEnd, runPreview]);

    const add = (): void => setMetrics([...metrics, { key: `m${metrics.length + 1}`, label: '', formula: '' }]);
    const update = (index: number, patch: Partial<CalcMetric>): void => setMetrics(metrics.map((metric, i) => (i === index ? { ...metric, ...patch } : metric)));
    const remove = (index: number): void => setMetrics(metrics.filter((_, i) => i !== index));

    const clean = (list: CalcMetric[]): CalcMetric[] => list.filter((metric) => metric.key !== '' && metric.formula.trim() !== '');
    const save = (): void => {
        if (scope === 'agency') {
            saveAgency.mutate(clean(agency));
        } else if (siteId !== null) {
            saveSite.mutate(clean(site));
        }
    };
    const saving = scope === 'agency' ? saveAgency.isPending : saveSite.isPending;
    const saved = scope === 'agency' ? saveAgency.isSuccess : saveSite.isSuccess;

    return (
        <Modal onClose={onClose} className="ir-max-w-3xl">
            <Card
                title="Métricas calculadas"
                description="Fórmulas sobre tus métricas (p. ej. ingresos ÷ pedidos = ticket medio). El «=» muestra el resultado con datos reales del periodo seleccionado."
                actions={
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cerrar
                    </Button>
                }
            >
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <div className="ir-grid ir-grid-cols-2 ir-gap-1 ir-rounded-lg ir-bg-muted ir-p-0.5 ir-text-sm">
                        {([
                            { value: 'agency', label: 'Agencia (todos los reportes)', Icon: Globe, count: agency.length },
                            { value: 'site', label: 'Solo este sitio', Icon: Building2, count: site.length },
                        ] as const).map(({ value, label, Icon, count }) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setScope(value)}
                                className={`ir-flex ir-items-center ir-justify-center ir-gap-1.5 ir-rounded ir-px-2 ir-py-1.5 ir-transition ${
                                    scope === value ? 'ir-bg-background ir-font-medium ir-shadow-sm' : 'ir-text-muted-foreground'
                                }`}
                            >
                                <Icon className="ir-size-4" />
                                {label}
                                {count > 0 && <span className="ir-rounded-full ir-bg-primary/10 ir-px-1.5 ir-text-[10px] ir-text-primary">{count}</span>}
                            </button>
                        ))}
                    </div>

                    <p className="ir-text-[11px] ir-text-muted-foreground">
                        {scope === 'agency'
                            ? 'Disponibles en TODOS los reportes de la agencia.'
                            : 'Solo para este sitio; si comparten clave, la del sitio gana sobre la de la agencia.'}
                    </p>

                    {siteId === null && (
                        <p className="ir-rounded-md ir-bg-amber-50 ir-p-2 ir-text-[11px] ir-text-amber-700">Elige un sitio arriba para ver el resultado de cada fórmula con datos reales.</p>
                    )}

                    <CalcMetricsEditor metrics={metrics} catalog={catalog} onAdd={add} onUpdate={update} onRemove={remove} values={values} />

                    <div className="ir-flex ir-items-center ir-justify-end ir-gap-2 ir-border-t ir-pt-3">
                        {saved && <span className="ir-text-xs ir-text-emerald-600">Guardadas.</span>}
                        <Button onClick={save} disabled={saving || (scope === 'site' && siteId === null)}>
                            {saving ? 'Guardando…' : scope === 'agency' ? 'Guardar (agencia)' : 'Guardar (sitio)'}
                        </Button>
                    </div>
                </div>
            </Card>
        </Modal>
    );
}
