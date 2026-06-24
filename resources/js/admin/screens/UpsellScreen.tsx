import {
    Activity,
    BadgeDollarSign,
    type LucideIcon,
    Lightbulb,
    ShieldAlert,
    ShieldOff,
    TrendingUp,
} from 'lucide-react';
import { type ReactElement } from 'react';

import { useUpsell } from '../api';
import { Badge, Card } from '../components/ui';
import type { SiteUpsell, UpsellOpportunityView } from '../types';

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

function OpportunityRow({ opportunity }: { opportunity: UpsellOpportunityView }): ReactElement {
    const p = present(opportunity);
    const Icon = p.icon;

    return (
        <li className="ir-flex ir-gap-3 ir-py-3">
            <div className="ir-mt-0.5 ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted">
                <Icon className="ir-size-4 ir-text-muted-foreground" />
            </div>
            <div className="ir-flex ir-flex-col ir-gap-1">
                <Badge tone={p.tone} className="ir-w-fit">
                    {p.title}
                </Badge>
                {p.sentence(opportunity.context) !== '' && (
                    <p className="ir-text-sm">{p.sentence(opportunity.context)}</p>
                )}
                {p.recommendation !== '' && (
                    <p className="ir-text-xs ir-text-muted-foreground">
                        <span className="ir-font-medium">Acción sugerida:</span> {p.recommendation}
                    </p>
                )}
            </div>
        </li>
    );
}

function SiteCard({ site }: { site: SiteUpsell }): ReactElement {
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
                    <OpportunityRow key={`${opportunity.type}-${index}`} opportunity={opportunity} />
                ))}
            </ul>
        </Card>
    );
}

export function UpsellScreen(): ReactElement {
    const { data: upsell, isLoading } = useUpsell();

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

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <p className="ir-text-sm ir-text-muted-foreground">
                Señales comerciales detectadas a partir del último reporte de cada sitio: crecimiento, presión de
                seguridad y huecos de cobertura. <span className="ir-font-medium">Solo para uso interno</span> — el
                cliente nunca las ve.
            </p>

            <div className="ir-grid ir-grid-cols-1 ir-gap-4 sm:ir-grid-cols-3">
                <Card title="Sitios evaluados">
                    <p className="ir-text-3xl ir-font-semibold">{upsell.summary.sites_count}</p>
                </Card>
                <Card title="Sitios con oportunidades">
                    <p className="ir-text-3xl ir-font-semibold ir-text-accent">{upsell.summary.sites_with_opportunities}</p>
                </Card>
                <Card title="Oportunidades totales">
                    <p className="ir-text-3xl ir-font-semibold">{upsell.summary.opportunities_count}</p>
                </Card>
            </div>

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
                        <SiteCard key={site.site_id} site={site} />
                    ))}
                </div>
            )}
        </div>
    );
}
