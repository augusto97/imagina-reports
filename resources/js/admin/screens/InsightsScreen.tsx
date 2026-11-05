import { HeartPulse, Lightbulb, ShieldAlert, TrendingUp } from 'lucide-react';
import { type ReactElement } from 'react';

import { useAnomalies } from '../api';
import { PageHeader, Tabs } from '../components/ui';
import { useAdminUi } from '../store';
import { AlertsScreen } from './AlertsScreen';
import { RiskScreen } from './RiskScreen';
import { TrendsScreen } from './TrendsScreen';
import { UpsellScreen } from './UpsellScreen';

type InsightTab = 'trends' | 'upsell' | 'alerts' | 'risk';

const DESCRIPTIONS: Record<InsightTab, string> = {
    trends: 'Cómo evoluciona la salud de todos tus sitios, mes a mes, para detectar a quién dedicarle tiempo.',
    upsell: 'Señales comerciales detectadas en el último reporte de cada sitio. Solo las ve tu equipo, nunca el cliente.',
    alerts: 'Caídas de tráfico y picos de ataques detectados al generar los reportes. Actúa antes de que el cliente lo note.',
    risk: 'Los clientes que podrían cancelar, por qué y qué hacer: no abren sus informes, esperan una respuesta, su sitio empeora o no ven tu trabajo.',
};

/**
 * Análisis: the three "look across the whole portfolio" views, under one roof. They used to
 * be three separate entries in the sidebar, which made the menu long and the difference
 * between them unclear; tabs make them one place with three angles.
 */
export function InsightsScreen({ tab }: { tab: InsightTab }): ReactElement {
    const setView = useAdminUi((state) => state.setView);
    const { data: anomalies = [] } = useAnomalies();
    const open = anomalies.filter((alert) => alert.acknowledged_at === null).length;

    return (
        <div className="ir-flex ir-flex-col">
            <PageHeader title="Análisis" description={DESCRIPTIONS[tab]} />
            <Tabs
                className="ir-mb-6"
                value={tab}
                onChange={setView}
                items={[
                    { value: 'trends', label: 'Tendencias', icon: TrendingUp },
                    { value: 'upsell', label: 'Oportunidades', icon: Lightbulb },
                    { value: 'alerts', label: 'Alertas', icon: ShieldAlert, count: open > 0 ? open : undefined },
                    { value: 'risk', label: 'Riesgo de baja', icon: HeartPulse },
                ]}
            />
            {tab === 'trends' && <TrendsScreen />}
            {tab === 'upsell' && <UpsellScreen />}
            {tab === 'alerts' && <AlertsScreen />}
            {tab === 'risk' && <RiskScreen />}
        </div>
    );
}
