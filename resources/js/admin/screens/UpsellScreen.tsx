import {
    Activity,
    BadgeDollarSign,
    Check,
    Clock,
    Copy,
    Gauge,
    type LucideIcon,
    Lightbulb,
    Mail,
    PenLine,
    ShieldAlert,
    ShieldOff,
    SlidersHorizontal,
    Sparkles,
    Target,
    TrendingUp,
    X,
} from 'lucide-react';
import { type ReactElement, useEffect, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import { useMarkOffered, useSaveUpsellThresholds, useUpsell, useUpsellProposal } from '../api';
import { Badge, Button, Card, Field, Input, Modal, StatCard } from '../components/ui';
import type { SiteUpsell, UpsellOpportunityView, UpsellThresholds } from '../types';

type Tone = 'success' | 'warning' | 'info' | 'danger' | 'neutral';

interface Presentation {
    title: string;
    tone: Tone;
    icon: LucideIcon;
    sentence: (context: Record<string, unknown>) => string;
    recommendation: string;
}

function num(value: unknown): number {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}

/** Localized, agency-facing presentation for each upsell signal (the API sends a stable `type`). */
const PRESENTATION: Record<string, Presentation> = {
    traffic_growth: {
        title: 'Crecimiento de tráfico',
        tone: 'success',
        icon: TrendingUp,
        sentence: (c) => `Las visitas subieron ${Math.round(num(c.change_percent))}% respecto al periodo anterior.`,
        recommendation: 'Propón un plan superior o servicios de SEO/marketing.',
    },
    sales_growth: {
        title: 'Crecimiento de ventas',
        tone: 'success',
        icon: BadgeDollarSign,
        sentence: (c) => `Los ingresos subieron ${Math.round(num(c.change_percent))}% respecto al periodo anterior.`,
        recommendation: 'Ofrece un plan de soporte mayor o nuevas funciones de tienda.',
    },
    security_hardening: {
        title: 'Presión de seguridad',
        tone: 'warning',
        icon: ShieldAlert,
        sentence: (c) => `Se bloquearon ${Math.round(num(c.attacks)).toLocaleString('es')} ataques en el periodo.`,
        recommendation: 'Ofrece el plan de seguridad reforzada / WAF gestionado.',
    },
    uptime_monitoring: {
        title: 'Sin monitoreo de disponibilidad',
        tone: 'info',
        icon: Activity,
        sentence: () => 'Este sitio no tiene monitoreo de uptime conectado.',
        recommendation: 'Añade monitoreo de disponibilidad (Better Stack).',
    },
    security_protection: {
        title: 'Sin protección de seguridad',
        tone: 'danger',
        icon: ShieldOff,
        sentence: () => 'Este sitio no tiene ninguna fuente de seguridad conectada.',
        recommendation: 'Ofrece protección (Cloudflare, CrowdSec o VirusDie).',
    },
    plan_overuse: {
        title: 'Usa más horas de las contratadas',
        tone: 'warning',
        icon: Clock,
        sentence: (c) => `Este mes fueron ${num(c.hours).toLocaleString('es')} h frente a ${num(c.plan_hours).toLocaleString('es')} h del plan (+${Math.round(num(c.over_percent))}%).`,
        recommendation: 'Propón el plan siguiente: más horas y mejor precio por hora.',
    },
    goals_reached: {
        title: 'Cumplió todas sus metas',
        tone: 'success',
        icon: Target,
        sentence: (c) => `Alcanzó sus ${num(c.goals)} metas del periodo.`,
        recommendation: 'Propón metas más altas y el plan de trabajo para lograrlas.',
    },
    performance: {
        title: 'Sitio lento',
        tone: 'warning',
        icon: Gauge,
        sentence: (c) => `Tarda de media ${Math.round(num(c.response_ms)).toLocaleString('es')} ms en responder.`,
        recommendation: 'Ofrece una optimización de velocidad.',
    },
};

function present(opportunity: UpsellOpportunityView): Presentation {
    return (
        PRESENTATION[opportunity.type] ?? {
            title: opportunity.label,
            tone: 'neutral',
            icon: Lightbulb,
            sentence: () => '',
            recommendation: '',
        }
    );
}

const TEXTAREA =
    'ir-w-full ir-rounded-lg ir-border ir-bg-card ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed ir-shadow-ir-xs ir-outline-none ir-transition focus:ir-border-accent focus:ir-ring-2 focus:ir-ring-ring/40';

/** «Preparar propuesta»: an editable email for the client, opening with their own number. */
function ProposalModal({ site, opportunity, onClose }: { site: SiteUpsell; opportunity: UpsellOpportunityView; onClose: () => void }): ReactElement {
    const draft = useUpsellProposal();
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [to, setTo] = useState(site.client_email ?? '');
    const [copied, setCopied] = useState(false);
    const { mutate } = draft;

    useEffect(() => {
        mutate(
            { site_id: site.site_id, type: opportunity.type },
            {
                onSuccess: (data) => {
                    setSubject(data.subject);
                    setBody(data.body);
                    if (data.to !== null && data.to !== '') setTo(data.to);
                },
            },
        );
    }, [mutate, site.site_id, opportunity.type]);

    const copy = (): void => {
        void navigator.clipboard?.writeText(`${subject}\n\n${body}`).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        });
    };
    const mailto = `mailto:${encodeURIComponent(to)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

    return (
        <Modal onClose={onClose} className="ir-max-w-2xl">
            <div className="ir-rounded-2xl ir-border ir-bg-card ir-p-6 ir-shadow-ir-lg">
                <div className="ir-flex ir-items-start ir-justify-between ir-gap-3">
                    <div>
                        <p className="ir-text-xs ir-font-medium ir-text-muted-foreground">
                            {site.site_name}
                            {site.client_name !== null ? ` · ${site.client_name}` : ''}
                        </p>
                        <h2 className="ir-flex ir-items-center ir-gap-2 ir-text-lg ir-font-semibold ir-tracking-tight">
                            Propuesta: {present(opportunity).title.toLowerCase()}
                            {draft.data?.ai === true && (
                                <Badge tone="accent">
                                    <Sparkles className="ir-size-3" />
                                    Escrita con IA
                                </Badge>
                            )}
                        </h2>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                        <X className="ir-size-4" />
                    </button>
                </div>

                {draft.isPending ? (
                    <div className="ir-mt-5 ir-flex ir-flex-col ir-gap-3">
                        <div className="ir-h-10 ir-animate-pulse ir-rounded-lg ir-bg-muted" />
                        <div className="ir-h-56 ir-animate-pulse ir-rounded-lg ir-bg-muted" />
                        <p className="ir-text-xs ir-text-muted-foreground">Preparando el borrador con los números del cliente…</p>
                    </div>
                ) : draft.isError ? (
                    <p className="ir-mt-5 ir-text-sm ir-text-danger">{apiErrorMessage(draft.error, 'No se pudo preparar la propuesta.')}</p>
                ) : (
                    <div className="ir-mt-5 ir-flex ir-flex-col ir-gap-4">
                        <Field label="Para">
                            <Input type="email" value={to} onChange={(event) => setTo(event.target.value)} placeholder="correo del cliente" />
                        </Field>
                        <Field label="Asunto">
                            <Input value={subject} onChange={(event) => setSubject(event.target.value)} />
                        </Field>
                        <Field label="Mensaje" hint="Es un borrador: cámbialo a tu gusto. Se envía desde tu propio correo.">
                            <textarea className={TEXTAREA} rows={11} value={body} onChange={(event) => setBody(event.target.value)} />
                        </Field>
                        <div className="ir-flex ir-flex-wrap ir-justify-end ir-gap-2">
                            <Button variant="ghost" onClick={copy}>
                                {copied ? <Check className="ir-size-4" /> : <Copy className="ir-size-4" />}
                                {copied ? 'Copiado' : 'Copiar'}
                            </Button>
                            <a href={mailto} className="ir-inline-flex ir-h-9 ir-items-center ir-gap-2 ir-rounded-lg ir-bg-accent ir-px-4 ir-text-sm ir-font-medium ir-text-accent-foreground hover:ir-opacity-90">
                                <Mail className="ir-size-4" />
                                Abrir en tu correo
                            </a>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}

const THRESHOLD_FIELDS: { key: keyof UpsellThresholds; label: string; hint: string; suffix: string }[] = [
    { key: 'traffic_growth_pct', label: 'Crecimiento de visitas', hint: 'Frente al periodo anterior.', suffix: '%' },
    { key: 'sales_growth_pct', label: 'Crecimiento de ventas', hint: 'Frente al periodo anterior.', suffix: '%' },
    { key: 'security_min_total', label: 'Ataques bloqueados', hint: 'Suma del periodo (Cloudflare + CrowdSec).', suffix: 'ataques' },
    { key: 'plan_overuse_pct', label: 'Horas por encima del plan', hint: 'Trabajo registrado frente a las horas del plan del sitio.', suffix: '%' },
    { key: 'slow_response_ms', label: 'Tiempo de respuesta', hint: 'Media del monitoreo de disponibilidad.', suffix: 'ms' },
];

function ThresholdsModal({ thresholds, onClose }: { thresholds: UpsellThresholds; onClose: () => void }): ReactElement {
    const save = useSaveUpsellThresholds();
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(THRESHOLD_FIELDS.map((field) => [field.key, String(thresholds[field.key])])));

    return (
        <Modal onClose={onClose} className="ir-max-w-lg">
            <form
                className="ir-rounded-2xl ir-border ir-bg-card ir-p-6 ir-shadow-ir-lg"
                onSubmit={(event) => {
                    event.preventDefault();
                    save.mutate(Object.fromEntries(THRESHOLD_FIELDS.map((field) => [field.key, Number(values[field.key])])) as Partial<UpsellThresholds>, { onSuccess: onClose });
                }}
            >
                <h2 className="ir-text-lg ir-font-semibold ir-tracking-tight">Cuándo avisarte</h2>
                <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">Una señal aparece como oportunidad a partir de estos valores. Ajústalos a cómo vendes tú.</p>
                <div className="ir-mt-5 ir-flex ir-flex-col ir-gap-4">
                    {THRESHOLD_FIELDS.map((field) => (
                        <Field key={field.key} label={field.label} hint={field.hint}>
                            <div className="ir-flex ir-items-center ir-gap-2">
                                <Input type="number" min={0} step="any" value={values[field.key] ?? ''} onChange={(event) => setValues({ ...values, [field.key]: event.target.value })} className="ir-max-w-[10rem]" />
                                <span className="ir-text-sm ir-text-muted-foreground">{field.suffix}</span>
                            </div>
                        </Field>
                    ))}
                </div>
                {save.isError && <p className="ir-mt-3 ir-text-xs ir-text-danger">{apiErrorMessage(save.error, 'No se pudo guardar.')}</p>}
                <div className="ir-mt-6 ir-flex ir-justify-end ir-gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={save.isPending}>
                        {save.isPending ? 'Guardando…' : 'Guardar'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function OpportunityRow({ site, opportunity, onPropose }: { site: SiteUpsell; opportunity: UpsellOpportunityView; onPropose: () => void }): ReactElement {
    const p = present(opportunity);
    const Icon = p.icon;
    const offered = useMarkOffered();

    return (
        <li className="ir-flex ir-flex-wrap ir-gap-3 ir-py-3">
            <div className="ir-mt-0.5 ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted">
                <Icon className="ir-size-4 ir-text-muted-foreground" />
            </div>
            <div className="ir-flex ir-min-w-[14rem] ir-flex-1 ir-flex-col ir-gap-1">
                <Badge tone={p.tone} className="ir-w-fit">
                    {p.title}
                </Badge>
                {p.sentence(opportunity.context) !== '' && <p className="ir-text-sm">{p.sentence(opportunity.context)}</p>}
                {p.recommendation !== '' && (
                    <p className="ir-text-xs ir-text-muted-foreground">
                        <span className="ir-font-medium">Acción sugerida:</span> {p.recommendation}
                    </p>
                )}
            </div>
            <div className="ir-flex ir-items-start ir-gap-1.5">
                <Button size="sm" variant="ghost" onClick={() => offered.mutate({ site_id: site.site_id, type: opportunity.type })} disabled={offered.isPending} title="La ocultamos durante 90 días">
                    <Check className="ir-size-3.5" />
                    Ya la ofrecí
                </Button>
                <Button size="sm" variant="accent" onClick={onPropose}>
                    <PenLine className="ir-size-3.5" />
                    Preparar propuesta
                </Button>
            </div>
        </li>
    );
}

function SiteCard({ site, onPropose }: { site: SiteUpsell; onPropose: (opportunity: UpsellOpportunityView) => void }): ReactElement {
    return (
        <Card
            title={site.site_name}
            description={[site.client_name ?? undefined, `Periodo: ${site.period_end}`].filter(Boolean).join(' · ')}
            actions={
                <Badge tone="accent">
                    {site.opportunities.length} {site.opportunities.length === 1 ? 'oportunidad' : 'oportunidades'}
                </Badge>
            }
        >
            <ul className="ir-divide-y">
                {site.opportunities.map((opportunity, index) => (
                    <OpportunityRow key={`${opportunity.type}-${index}`} site={site} opportunity={opportunity} onPropose={() => onPropose(opportunity)} />
                ))}
            </ul>
        </Card>
    );
}

export function UpsellScreen(): ReactElement {
    const { data: upsell, isLoading, isError, refetch } = useUpsell();
    const [proposal, setProposal] = useState<{ site: SiteUpsell; opportunity: UpsellOpportunityView } | null>(null);
    const [tuning, setTuning] = useState(false);

    if (isError) {
        return (
            <Card title="Oportunidades de venta">
                <p className="ir-text-sm ir-text-danger">No se pudieron cargar las oportunidades.</p>
                <button type="button" onClick={() => void refetch()} className="ir-mt-2 ir-text-sm ir-font-medium ir-text-primary hover:ir-underline">
                    Reintentar
                </button>
            </Card>
        );
    }

    if (isLoading || upsell === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando oportunidades…</p>;
    }

    if (upsell.summary.sites_count === 0) {
        return (
            <Card title="Oportunidades de venta">
                <p className="ir-text-sm ir-text-muted-foreground">
                    Aún no hay reportes generados. Genera reportes para que el sistema detecte oportunidades
                    de upsell a partir de los datos de cada cliente.
                </p>
            </Card>
        );
    }

    const offered = upsell.summary.offered_count ?? 0;

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <div className="ir-grid ir-grid-cols-1 ir-gap-3 sm:ir-grid-cols-3">
                <StatCard label="Sitios evaluados" value={upsell.summary.sites_count} />
                <StatCard label="Sitios con oportunidades" value={upsell.summary.sites_with_opportunities} tone="accent" />
                <StatCard label="Oportunidades abiertas" value={upsell.summary.opportunities_count} hint={offered > 0 ? `${offered} ya ofrecidas (ocultas 90 días)` : undefined} />
            </div>

            {upsell.thresholds !== undefined && (
                <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 ir-rounded-xl ir-border ir-bg-card ir-px-4 ir-py-3 ir-text-sm">
                    <p className="ir-text-muted-foreground">
                        Te avisamos con visitas +{upsell.thresholds.traffic_growth_pct}%, ventas +{upsell.thresholds.sales_growth_pct}%, {upsell.thresholds.security_min_total.toLocaleString('es')} ataques, horas +{upsell.thresholds.plan_overuse_pct}% sobre el plan o respuesta de {upsell.thresholds.slow_response_ms.toLocaleString('es')} ms.
                    </p>
                    <Button size="sm" variant="ghost" onClick={() => setTuning(true)}>
                        <SlidersHorizontal className="ir-size-3.5" />
                        Ajustar
                    </Button>
                </div>
            )}

            {upsell.sites.length === 0 ? (
                <Card title="Sin oportunidades por ahora">
                    <p className="ir-text-sm ir-text-muted-foreground">
                        No se detectaron señales de upsell en el último reporte de los sitios evaluados. Vuelve a
                        revisar cuando se generen nuevos reportes.
                    </p>
                </Card>
            ) : (
                <div className="ir-flex ir-flex-col ir-gap-4">
                    {upsell.sites.map((site) => (
                        <SiteCard key={site.site_id} site={site} onPropose={(opportunity) => setProposal({ site, opportunity })} />
                    ))}
                </div>
            )}

            {proposal !== null && <ProposalModal site={proposal.site} opportunity={proposal.opportunity} onClose={() => setProposal(null)} />}
            {tuning && upsell.thresholds !== undefined && <ThresholdsModal thresholds={upsell.thresholds} onClose={() => setTuning(false)} />}
        </div>
    );
}
