import { type LucideIcon, ShieldAlert, TrendingDown, TriangleAlert } from 'lucide-react';
import { type ReactElement } from 'react';

import { useAcknowledgeAnomaly, useAnomalies, useDeleteAnomaly } from '../api';
import { Badge, Button, Card } from '../components/ui';
import type { AnomalyAlert } from '../types';

interface Presentation {
    title: string;
    tone: 'danger' | 'warning';
    icon: LucideIcon;
    sentence: (alert: AnomalyAlert) => string;
    recommendation: string;
}

const PRESENTATION: Record<string, Presentation> = {
    traffic_drop: {
        title: 'Caída de tráfico',
        tone: 'warning',
        icon: TrendingDown,
        sentence: (a) => `Las visitas cayeron ${Math.abs(Math.round(a.change_percent))}% respecto al periodo anterior (${Math.round(a.previous).toLocaleString('es')} → ${Math.round(a.current).toLocaleString('es')}).`,
        recommendation: 'Revisa SEO, campañas activas y posibles errores del sitio; avisa al cliente antes de que lo note.',
    },
    attack_spike: {
        title: 'Pico de ataques',
        tone: 'danger',
        icon: ShieldAlert,
        sentence: (a) => `Los ataques bloqueados se dispararon (${Math.round(a.previous).toLocaleString('es')} → ${Math.round(a.current).toLocaleString('es')}).`,
        recommendation: 'Confirma que el firewall/WAF contuvo el ataque; es una buena ocasión para ofrecer seguridad reforzada.',
    },
};

const fallback: Presentation = {
    title: 'Anomalía',
    tone: 'warning',
    icon: TriangleAlert,
    sentence: (a) => `${a.metric}: ${Math.round(a.previous).toLocaleString('es')} → ${Math.round(a.current).toLocaleString('es')} (${Math.round(a.change_percent)}%).`,
    recommendation: 'Revisa el detalle del periodo.',
};

function formatDate(value: string | null): string {
    if (value === null) return '—';
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The in-app anomaly alerts feed (CLAUDE.md §13): traffic drops and attack spikes detected
 * when a report is generated. The same events also fire the `anomaly.detected` webhook.
 */
export function AlertsScreen(): ReactElement {
    const { data: alerts = [], isLoading } = useAnomalies();
    const acknowledge = useAcknowledgeAnomaly();
    const remove = useDeleteAnomaly();

    const open = alerts.filter((alert) => alert.acknowledged_at === null);
    const resolved = alerts.filter((alert) => alert.acknowledged_at !== null);

    const renderAlert = (alert: AnomalyAlert, isResolved: boolean): ReactElement => {
        const p = PRESENTATION[alert.type] ?? fallback;

        return (
            <div key={alert.id} className={`ir-flex ir-items-start ir-gap-3 ir-rounded-lg ir-border ir-p-4 ${isResolved ? 'ir-bg-muted/30 ir-opacity-70' : 'ir-bg-card'}`}>
                <span className={`ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ${p.tone === 'danger' ? 'ir-bg-danger/10 ir-text-danger' : 'ir-bg-warning/10 ir-text-warning'}`}>
                    <p.icon className="ir-size-5" />
                </span>
                <div className="ir-min-w-0 ir-flex-1">
                    <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                        <p className="ir-text-sm ir-font-semibold">{p.title}</p>
                        <Badge tone={p.tone}>{alert.site_name ?? `Sitio #${alert.site_id}`}</Badge>
                        {isResolved && <Badge tone="neutral">Resuelta</Badge>}
                    </div>
                    <p className="ir-mt-1 ir-text-sm ir-text-foreground/90">{p.sentence(alert)}</p>
                    <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">{p.recommendation}</p>
                    <p className="ir-mt-1 ir-text-[11px] ir-text-muted-foreground">Detectada {formatDate(alert.detected_at)}</p>
                </div>
                <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-2">
                    {!isResolved && (
                        <Button variant="outline" size="sm" onClick={() => acknowledge.mutate(alert.id)} disabled={acknowledge.isPending}>
                            Marcar resuelta
                        </Button>
                    )}
                    <Button variant="ghost" size="sm" title="Descartar" onClick={() => remove.mutate(alert.id)} disabled={remove.isPending}>
                        Descartar
                    </Button>
                </div>
            </div>
        );
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            <div>
                <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Alertas</h1>
                <p className="ir-mt-1 ir-max-w-2xl ir-text-sm ir-text-muted-foreground">
                    Anomalías detectadas automáticamente al generar los reportes: caídas de tráfico y picos de ataques. Actúa antes de que el cliente lo note.
                </p>
            </div>

            {isLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
            ) : alerts.length === 0 ? (
                <div className="ir-flex ir-flex-col ir-items-center ir-gap-3 ir-rounded-lg ir-border ir-border-dashed ir-bg-card ir-py-16 ir-text-center">
                    <span className="ir-flex ir-size-12 ir-items-center ir-justify-center ir-rounded-xl ir-bg-success/10 ir-text-success">
                        <TriangleAlert className="ir-size-6" />
                    </span>
                    <div>
                        <p className="ir-text-sm ir-font-medium">Todo en orden</p>
                        <p className="ir-mt-1 ir-max-w-sm ir-text-xs ir-text-muted-foreground">No hay anomalías. Aparecerán aquí en cuanto se detecten al generar un reporte.</p>
                    </div>
                </div>
            ) : (
                <>
                    {open.length > 0 && (
                        <Card title={`Sin resolver (${open.length})`}>
                            <div className="ir-flex ir-flex-col ir-gap-3">{open.map((alert) => renderAlert(alert, false))}</div>
                        </Card>
                    )}
                    {resolved.length > 0 && (
                        <Card title={`Resueltas (${resolved.length})`}>
                            <div className="ir-flex ir-flex-col ir-gap-3">{resolved.map((alert) => renderAlert(alert, true))}</div>
                        </Card>
                    )}
                </>
            )}
        </div>
    );
}
