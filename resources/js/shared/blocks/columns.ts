/**
 * Readable names for the row keys connectors hand back («clicks», «impressions», «screenPageViews»…),
 * shared by the report's tables and the editor's «Orden» picker so both say the same thing.
 * Unknown keys are humanised (underscores and camelCase become spaces, first letter up); add the
 * common ones here when a source brings a new one.
 */
const LABELS: Record<string, string> = {
    label: 'Nombre',
    name: 'Nombre',
    title: 'Título',
    value: 'Valor',
    date: 'Fecha',
    count: 'Total',
    total: 'Total',
    // Search / web analytics
    query: 'Búsqueda',
    page: 'Página',
    pagepath: 'Página',
    url: 'Dirección',
    clicks: 'Clics',
    impressions: 'Apariciones',
    ctr: '% de clics',
    position: 'Posición',
    sessions: 'Visitas',
    users: 'Usuarios',
    newusers: 'Usuarios nuevos',
    views: 'Vistas',
    pageviews: 'Vistas',
    screenpageviews: 'Vistas',
    bouncerate: 'Rebote',
    engagementrate: 'Interacción',
    country: 'País',
    city: 'Ciudad',
    region: 'Región',
    device: 'Dispositivo',
    devicecategory: 'Dispositivo',
    browser: 'Navegador',
    source: 'Fuente',
    medium: 'Medio',
    channel: 'Canal',
    sessiondefaultchannelgroup: 'Canal',
    // Ads
    campaign: 'Campaña',
    adset: 'Conjunto',
    spend: 'Inversión',
    cost: 'Inversión',
    reach: 'Alcance',
    cpc: 'Costo por clic',
    cpm: 'Costo por mil',
    conversions: 'Conversiones',
    conversion_value: 'Valor conv.',
    roas: 'Retorno',
    // Store
    revenue: 'Ingresos',
    orders: 'Pedidos',
    quantity: 'Cantidad',
    items_sold: 'Vendidos',
    product: 'Producto',
    category: 'Categoría',
    customer: 'Cliente',
    spent: 'Gasto',
    // Maintenance / security
    status: 'Estado',
    version: 'Versión',
    plugin: 'Plugin',
    theme: 'Tema',
    type: 'Tipo',
    size: 'Tamaño',
    hours: 'Horas',
    description: 'Descripción',
    ip: 'Dirección IP',
    attacks: 'Ataques',
    threats: 'Amenazas',
};

export function columnLabel(key: string): string {
    const known = LABELS[key.toLowerCase()] ?? LABELS[key.toLowerCase().replace(/[_\s-]/g, '')];
    if (known !== undefined) {
        return known;
    }
    const spaced = key.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').trim();

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/** Money columns (ads spend, store revenue…), shown in the report's currency. */
const MONEY = /(^|_)(revenue|spend|spent|cost|cpc|cpm|cpa|sales|price|amount|aov)$|conversions?_value$|^total_sales$|^net_sales$|^average_sales$/;

/** Columns whose numbers read better with their own format (a CTR as a %, a position with one decimal, money). */
export function formatColumnValue(key: string, value: number, locale: string, currency?: string): string | null {
    const k = key.toLowerCase();
    if (currency !== undefined && MONEY.test(k)) {
        return new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: Math.abs(value) < 100 ? 2 : 0 }).format(value);
    }
    if (k === 'roas') {
        return `${value.toLocaleString(locale, { maximumFractionDigits: 2 })}×`;
    }
    if (/(^|_)(rate|share|percent|pct)$/.test(k) && k !== 'ctr') {
        return `${value.toLocaleString(locale, { maximumFractionDigits: 1 })} %`;
    }
    if (/(^|_)ms$|response_time/.test(k)) {
        return `${Math.round(value).toLocaleString(locale)} ms`;
    }
    if (k === 'ctr' || k === 'bouncerate' || k === 'engagementrate' || k === 'bounce_rate' || k === 'engagement_rate') {
        // Connectors store rates as a percentage (5.4 = 5,4 %), never as a fraction: a 0.5 % CTR
        // must not read as 50 %.
        return `${value.toLocaleString(locale, { maximumFractionDigits: 1 })} %`;
    }
    if (k === 'position') {
        return value.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }

    return null;
}
