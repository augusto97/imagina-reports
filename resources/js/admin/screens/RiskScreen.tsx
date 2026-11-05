import { ArrowRight, CheckCircle2, HeartPulse } from 'lucide-react';
import { type ReactElement } from 'react';

import { cn } from '@shared/lib/utils';

import { useChurnRisk } from '../api';
import { Card, StatCard } from '../components/ui';
import { useAdminUi } from '../store';
import type { ChurnRiskSite } from '../types';

const LEVEL: Record<ChurnRiskSite['level'], { label: string; ring: string; chip: string }> = {
    high: { label: 'Riesgo alto', ring: 'ir-text-danger', chip: 'ir-bg-danger/10 ir-text-danger' },
    medium: { label: 'Riesgo medio', ring: 'ir-text-warning', chip: 'ir-bg-warning/10 ir-text-warning' },
    low: { label: 'Riesgo bajo', ring: 'ir-text-muted-foreground', chip: 'ir-bg-muted ir-text-muted-foreground' },
};

const ACTIONS: Record<string, string> = {
    reports: 'Ver sus reportes',
    questions: 'Responder',
    site: 'Revisar el sitio',
    worklogs: 'Registrar trabajo',
};

function Score({ value, level }: { value: number; level: ChurnRiskSite['level'] }): ReactElement {
    const radius = 18;
    const circumference = 2 * Math.PI * radius;

    return (
        <span className={cn('ir-relative ir-flex ir-size-12 ir-shrink-0 ir-items-center ir-justify-center', LEVEL[level].ring)}>
            <svg viewBox="0 0 44 44" className="ir-absolute ir-inset-0 -ir-rotate-90">
                <circle cx="22" cy="22" r={radius} fill="none" stroke="currentColor" strokeOpacity={0.15} strokeWidth="4" />
                <circle cx="22" cy="22" r={radius} fill="none" stroke="currentColor" strokeWidth="4" strokeLinecap="round" strokeDasharray={`${(value / 100) * circumference} ${circumference}`} />
            </svg>
            <span className="ir-text-sm ir-font-semibold ir-tabular-nums">{value}</span>
        </span>
    );
}

function SiteRisk({ risk }: { risk: ChurnRiskSite }): ReactElement {
    const setView = useAdminUi((state) => state.setView);
    const selectSite = useAdminUi((state) => state.selectSite);
    const focusReport = useAdminUi((state) => state.focusReport);

    const act = (action: string): void => {
        if (action === 'questions' && risk.latest_report_id !== null) focusReport(risk.latest_report_id, 'questions');
        else if (action === 'site') selectSite(risk.site_id);
        else if (action === 'worklogs') setView('worklogs');
        else setView('reports');
    };

    return (
        <li className="ir-flex ir-flex-col ir-gap-3 ir-rounded-xl ir-border ir-bg-card ir-p-4 ir-shadow-ir-xs sm:ir-flex-row sm:ir-items-start">
            <div className="ir-flex ir-min-w-0 ir-items-center ir-gap-3 sm:ir-w-64 sm:ir-shrink-0">
                <Score value={risk.score} level={risk.level} />
                <div className="ir-min-w-0">
                    <p className="ir-truncate ir-text-sm ir-font-semibold">{risk.site_name}</p>
                    {risk.client_name !== null && <p className="ir-truncate ir-text-xs ir-text-muted-foreground">{risk.client_name}</p>}
                    <span className={cn('ir-mt-1 ir-inline-flex ir-rounded-full ir-px-2 ir-py-0.5 ir-text-[11px] ir-font-semibold', LEVEL[risk.level].chip)}>{LEVEL[risk.level].label}</span>
                </div>
            </div>
            <ul className="ir-flex ir-min-w-0 ir-flex-1 ir-flex-col ir-divide-y">
                {risk.reasons.map((reason) => (
                    <li key={reason.key} className="ir-flex ir-items-center ir-justify-between ir-gap-3 ir-py-2 first:ir-pt-0 last:ir-pb-0">
                        <span className="ir-text-sm">{reason.text}</span>
                        <button type="button" onClick={() => act(reason.action)} className="ir-group ir-inline-flex ir-shrink-0 ir-items-center ir-gap-1 ir-text-xs ir-font-medium ir-text-muted-foreground hover:ir-text-foreground">
                            {ACTIONS[reason.action] ?? 'Ver'}
                            <ArrowRight className="ir-size-3.5 ir-transition-transform group-hover:ir-translate-x-0.5" />
                        </button>
                    </li>
                ))}
            </ul>
        </li>
    );
}

/**
 * Riesgo de baja (Etapa 5): the clients to look after first, each with why in plain words and a
 * button to the place that fixes it. Built from what the app already knows — opened reports,
 * unanswered questions, health, broken connections, goals and logged work.
 */
export function RiskScreen(): ReactElement {
    const { data, isLoading, isError } = useChurnRisk();

    if (isError) {
        return <p className="ir-text-sm ir-text-danger">No se pudo calcular el riesgo.</p>;
    }
    if (isLoading || data === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Calculando…</p>;
    }

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <div className="ir-grid ir-grid-cols-1 ir-gap-3 sm:ir-grid-cols-3">
                <StatCard label="Riesgo alto" value={data.summary.high} hint="Atiéndelos esta semana" tone={data.summary.high > 0 ? 'danger' : 'neutral'} icon={HeartPulse} />
                <StatCard label="Riesgo medio" value={data.summary.medium} hint="Una llamada a tiempo ayuda" tone={data.summary.medium > 0 ? 'warning' : 'neutral'} />
                <StatCard label="Clientes con reporte" value={data.summary.sites} hint={`${data.summary.sites - data.sites.length} sin señales de riesgo`} tone="success" />
            </div>

            {data.sites.length === 0 ? (
                <Card>
                    <div className="ir-flex ir-flex-col ir-items-center ir-gap-2 ir-py-6 ir-text-center">
                        <CheckCircle2 className="ir-size-7 ir-text-success" />
                        <p className="ir-text-sm ir-font-medium">Ningún cliente da señales de riesgo</p>
                        <p className="ir-max-w-md ir-text-xs ir-text-muted-foreground">Abren sus informes, no tienen preguntas pendientes, sus conexiones funcionan y ven tu trabajo. Así se ve una cartera sana.</p>
                    </div>
                </Card>
            ) : (
                <ul className="ir-flex ir-flex-col ir-gap-3">
                    {data.sites.map((risk) => (
                        <SiteRisk key={risk.site_id} risk={risk} />
                    ))}
                </ul>
            )}
        </div>
    );
}
