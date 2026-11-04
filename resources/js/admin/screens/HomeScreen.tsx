import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    Circle,
    Clock,
    FileBarChart,
    FilePlus2,
    Plug,
    Send,
    ShieldAlert,
    Sparkles,
    type LucideIcon,
} from 'lucide-react';
import { type ReactElement, type ReactNode } from 'react';

import { useAuthUser, useOverview } from '../api';
import { Button, Card, PageHeader, StatCard } from '../components/ui';
import { monthLabel } from '../lib/months';
import { type AdminView, useAdminUi } from '../store';

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches';
}

function TaskRow({
    icon: Icon,
    tone,
    title,
    detail,
    action,
    onClick,
}: {
    icon: LucideIcon;
    tone: 'danger' | 'warning' | 'accent' | 'success';
    title: ReactNode;
    detail?: ReactNode;
    action: string;
    onClick: () => void;
}): ReactElement {
    const tones = {
        danger: 'ir-bg-danger/10 ir-text-danger',
        warning: 'ir-bg-warning/10 ir-text-warning',
        accent: 'ir-bg-accent/10 ir-text-accent',
        success: 'ir-bg-success/10 ir-text-success',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            className="ir-group ir-flex ir-w-full ir-items-center ir-gap-3 ir-rounded-lg ir-px-2 ir-py-2.5 ir-text-left ir-transition-colors hover:ir-bg-muted/60"
        >
            <span className={`ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ${tones[tone]}`}>
                <Icon className="ir-size-4" />
            </span>
            <span className="ir-min-w-0 ir-flex-1">
                <span className="ir-block ir-truncate ir-text-sm ir-font-medium">{title}</span>
                {detail !== undefined && <span className="ir-block ir-truncate ir-text-xs ir-text-muted-foreground">{detail}</span>}
            </span>
            <span className="ir-hidden ir-shrink-0 ir-items-center ir-gap-1 ir-text-xs ir-font-medium ir-text-muted-foreground group-hover:ir-text-foreground sm:ir-flex">
                {action}
                <ArrowRight className="ir-size-3.5 ir-transition-transform group-hover:ir-translate-x-0.5" />
            </span>
        </button>
    );
}

/** First-run checklist: the four steps from "no clients" to "a report in the client's inbox". */
function Onboarding({ counts, go, onSite }: { counts: { clients: number; sites: number; sources: number; reports: number }; go: (view: AdminView) => void; onSite: () => void }): ReactElement {
    const steps: { done: boolean; title: string; text: string; action: string; onClick: () => void }[] = [
        { done: counts.clients > 0 && counts.sites > 0, title: 'Crea un cliente y su sitio', text: 'La empresa a la que le haces el reporte y su web.', action: 'Ir a Clientes', onClick: () => go('clients') },
        { done: counts.sources > 0, title: 'Conecta sus fuentes de datos', text: 'Google Analytics, Search Console, MainWP… con un clic y solo lectura.', action: 'Conectar fuentes', onClick: onSite },
        { done: counts.reports > 0, title: 'Crea su reporte mensual', text: 'Elige una plantilla (o pídesela a la IA) y genera el primero.', action: 'Ir a Reportes', onClick: () => go('reports') },
        { done: false, title: 'Revísalo y envíalo', text: 'Ajusta el resumen, apruébalo y llega al correo del cliente con PDF y panel.', action: 'Ver reportes', onClick: () => go('reports') },
    ];
    const done = steps.filter((step) => step.done).length;

    return (
        <Card title="Primeros pasos" description={`${done} de ${steps.length} completados. Sigue el orden y tendrás tu primer reporte enviado en minutos.`}>
            <div className="ir-mb-4 ir-h-1.5 ir-overflow-hidden ir-rounded-full ir-bg-muted">
                <div className="ir-h-full ir-rounded-full ir-bg-accent ir-transition-all" style={{ width: `${(done / steps.length) * 100}%` }} />
            </div>
            <ol className="ir-flex ir-flex-col ir-gap-1">
                {steps.map((step, index) => (
                    <li key={step.title} className="ir-flex ir-items-start ir-gap-3 ir-rounded-lg ir-px-2 ir-py-2.5">
                        {step.done ? <CheckCircle2 className="ir-mt-0.5 ir-size-5 ir-shrink-0 ir-text-success" /> : <Circle className="ir-mt-0.5 ir-size-5 ir-shrink-0 ir-text-muted-foreground/50" />}
                        <div className="ir-min-w-0 ir-flex-1">
                            <p className={`ir-text-sm ir-font-medium ${step.done ? 'ir-text-muted-foreground ir-line-through' : ''}`}>
                                {index + 1}. {step.title}
                            </p>
                            <p className="ir-text-xs ir-text-muted-foreground">{step.text}</p>
                        </div>
                        {!step.done && (
                            <Button size="sm" variant="ghost" onClick={step.onClick}>
                                {step.action}
                            </Button>
                        )}
                    </li>
                ))}
            </ol>
        </Card>
    );
}

/**
 * Inicio: the first thing an agency sees. Instead of dropping people into a tree of clients,
 * it answers "what do I have to do today?" — sources that broke, reports waiting for review
 * or to be sent, last month's reports not generated yet — each one a click from the fix.
 */
export function HomeScreen(): ReactElement {
    const { data: user } = useAuthUser();
    const { data, isLoading } = useOverview();
    const setView = useAdminUi((state) => state.setView);
    const selectSite = useAdminUi((state) => state.selectSite);
    const firstName = (user?.name ?? '').split(' ')[0] ?? '';

    if (isLoading || data === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>;
    }

    const { counts } = data;
    const firstSiteWithoutSources = Object.entries(data.site_health).find(([, health]) => health.sources === 0)?.[0];
    const needsOnboarding = counts.sites === 0 || counts.sources === 0 || counts.definitions === 0;

    const tasks: ReactElement[] = [
        ...data.failing_sources.map((source) => (
            <TaskRow
                key={`s${source.id}`}
                icon={Plug}
                tone="danger"
                title={`${source.label} falla en ${source.site}`}
                detail={source.error ?? 'La última sincronización no funcionó.'}
                action="Revisar"
                onClick={() => source.site_id !== null && selectSite(source.site_id)}
            />
        )),
        ...data.missing_reports.map((item) => (
            <TaskRow
                key={`m${item.definition_id}`}
                icon={FilePlus2}
                tone="warning"
                title={`Falta el reporte de ${monthLabel(data.last_month)}`}
                detail={`${item.site}${item.client !== null ? ` · ${item.client}` : ''}`}
                action="Generar"
                onClick={() => setView('reports')}
            />
        )),
        ...data.drafts.map((report) => (
            <TaskRow
                key={`d${report.id}`}
                icon={FileBarChart}
                tone="accent"
                title={`Revisar y aprobar: ${report.site}`}
                detail={`${monthLabel(report.period_start)}${report.client !== null ? ` · ${report.client}` : ''}`}
                action="Revisar"
                onClick={() => setView('reports')}
            />
        )),
        ...data.approved.map((report) => (
            <TaskRow
                key={`a${report.id}`}
                icon={Send}
                tone="success"
                title={`Listo para enviar: ${report.site}`}
                detail={`${monthLabel(report.period_start)}${report.client !== null ? ` · ${report.client}` : ''}`}
                action="Enviar"
                onClick={() => setView('reports')}
            />
        )),
    ];

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <PageHeader
                className="ir-mb-1"
                eyebrow={new Date().toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })}
                title={`${greeting()}${firstName !== '' ? `, ${firstName}` : ''}`}
                description="Esto es lo que necesita tu atención. Cada tarea te lleva directo a donde se resuelve."
                actions={
                    <>
                        <Button variant="ghost" onClick={() => setView('worklogs')}>
                            <Clock className="ir-size-4" />
                            Registrar trabajo
                        </Button>
                        <Button onClick={() => setView('reports')}>
                            <FileBarChart className="ir-size-4" />
                            Ir a reportes
                        </Button>
                    </>
                }
            />

            <div className="ir-grid ir-grid-cols-2 ir-gap-3 lg:ir-grid-cols-4">
                <StatCard label="Clientes" value={counts.clients} hint={`${counts.sites} sitios`} icon={Building2} tone="neutral" onClick={() => setView('clients')} />
                <StatCard label="Por revisar" value={counts.drafts} hint="Reportes en borrador" icon={FileBarChart} tone="accent" onClick={() => setView('reports')} />
                <StatCard label="Listos para enviar" value={counts.approved} hint="Aprobados, sin enviar" icon={Send} tone="success" onClick={() => setView('reports')} />
                <StatCard
                    label="Fuentes con error"
                    value={counts.sources_failing}
                    hint={`de ${counts.sources} conectadas`}
                    icon={AlertTriangle}
                    tone={counts.sources_failing > 0 ? 'danger' : 'neutral'}
                    onClick={() => setView('clients')}
                />
            </div>

            <div className="ir-grid ir-gap-6 lg:ir-grid-cols-[minmax(0,1fr)_320px]">
                <div className="ir-flex ir-min-w-0 ir-flex-col ir-gap-6">
                    {needsOnboarding && (
                        <Onboarding
                            counts={{ clients: counts.clients, sites: counts.sites, sources: counts.sources, reports: counts.definitions }}
                            go={setView}
                            onSite={() => (firstSiteWithoutSources !== undefined ? selectSite(Number(firstSiteWithoutSources)) : setView('clients'))}
                        />
                    )}

                    <Card title="Pendientes" description={tasks.length === 0 ? undefined : `${tasks.length} cosas por hacer, de la más urgente a la menos.`}>
                        {tasks.length === 0 ? (
                            <div className="ir-flex ir-flex-col ir-items-center ir-py-8 ir-text-center">
                                <span className="ir-flex ir-size-11 ir-items-center ir-justify-center ir-rounded-xl ir-bg-success/10 ir-text-success">
                                    <CheckCircle2 className="ir-size-5" />
                                </span>
                                <p className="ir-mt-3 ir-text-sm ir-font-semibold">Todo al día</p>
                                <p className="ir-mt-1 ir-text-[13px] ir-text-muted-foreground">No hay fuentes con error ni reportes pendientes.</p>
                            </div>
                        ) : (
                            <div className="-ir-mx-2 ir-flex ir-flex-col ir-divide-y ir-divide-border/60">{tasks}</div>
                        )}
                    </Card>
                </div>

                <div className="ir-flex ir-flex-col ir-gap-6">
                    <Card title="Atajos">
                        <div className="-ir-mx-2 ir-flex ir-flex-col">
                            <TaskRow icon={Building2} tone="accent" title="Nuevo cliente" detail="Empresa + su sitio web" action="Crear" onClick={() => setView('clients')} />
                            <TaskRow icon={Sparkles} tone="accent" title="Diseñar una plantilla" detail="Con la IA o desde cero" action="Abrir" onClick={() => setView('templates')} />
                            <TaskRow icon={Clock} tone="accent" title="Registrar trabajo" detail="Lo que justifica el plan" action="Anotar" onClick={() => setView('worklogs')} />
                        </div>
                    </Card>

                    <Card title="Salud de la cartera">
                        <div className="ir-flex ir-flex-col ir-gap-3 ir-text-sm">
                            <HealthLine label="Alertas sin revisar" value={counts.open_alerts} tone={counts.open_alerts > 0 ? 'danger' : 'ok'} onClick={() => setView('alerts')} icon={ShieldAlert} />
                            <HealthLine label="Sitios sin fuentes" value={counts.sites_without_sources} tone={counts.sites_without_sources > 0 ? 'warning' : 'ok'} onClick={() => setView('clients')} icon={Plug} />
                            <HealthLine label={`Reportes de ${monthLabel(data.last_month)} sin generar`} value={counts.missing_reports} tone={counts.missing_reports > 0 ? 'warning' : 'ok'} onClick={() => setView('reports')} icon={FilePlus2} />
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    );
}

function HealthLine({ label, value, tone, onClick, icon: Icon }: { label: string; value: number; tone: 'ok' | 'warning' | 'danger'; onClick: () => void; icon: LucideIcon }): ReactElement {
    const color = tone === 'ok' ? 'ir-text-success' : tone === 'warning' ? 'ir-text-warning' : 'ir-text-danger';

    return (
        <button type="button" onClick={onClick} className="ir-flex ir-items-center ir-gap-2.5 ir-text-left hover:ir-underline">
            <Icon className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
            <span className="ir-min-w-0 ir-flex-1 ir-text-muted-foreground">{label}</span>
            <span className={`ir-font-semibold ir-tabular-nums ${color}`}>{value === 0 ? '✓' : value}</span>
        </button>
    );
}
