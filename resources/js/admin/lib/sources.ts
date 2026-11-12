import {
    Activity,
    BarChart3,
    Bot,
    Bug,
    Cloud,
    Database,
    Facebook,
    Gauge,
    Instagram,
    Link2,
    Mail,
    MapPin,
    Megaphone,
    Search,
    ShieldAlert,
    ShoppingCart,
    Video,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

export type SourceCategory = 'analytics' | 'ads' | 'security' | 'maintenance' | 'sales' | 'custom';

/** How each data source reads in the panel: a recognisable icon, a short plain-language purpose. */
export const SOURCE_META: Record<string, { label: string; icon: LucideIcon; blurb: string; category: SourceCategory }> = {
    ga4: { label: 'Google Analytics 4', icon: BarChart3, blurb: 'Visitas, usuarios y conversiones', category: 'analytics' },
    gsc: { label: 'Google Search Console', icon: Search, blurb: 'Clics y posiciones en Google', category: 'analytics' },
    mailchimp: { label: 'Mailchimp', icon: Mail, blurb: 'Campañas de email', category: 'analytics' },
    instagram: { label: 'Instagram', icon: Instagram, blurb: 'Alcance y publicaciones', category: 'analytics' },
    google_business: { label: 'Perfil de Empresa de Google', icon: MapPin, blurb: 'Llamadas, rutas y reseñas en Google', category: 'analytics' },
    google_ads: { label: 'Google Ads', icon: Megaphone, blurb: 'Inversión y conversiones', category: 'ads' },
    facebook_ads: { label: 'Facebook / Meta Ads', icon: Facebook, blurb: 'Campañas de Meta', category: 'ads' },
    tiktok_ads: { label: 'TikTok Ads', icon: Video, blurb: 'Campañas de TikTok', category: 'ads' },
    cloudflare: { label: 'Cloudflare', icon: Cloud, blurb: 'Tráfico, caché y amenazas', category: 'security' },
    crowdsec: { label: 'CrowdSec', icon: ShieldAlert, blurb: 'Ataques bloqueados', category: 'security' },
    virusdie: { label: 'Virusdie', icon: Bug, blurb: 'Malware y firewall', category: 'security' },
    betteruptime: { label: 'Better Stack (Uptime)', icon: Activity, blurb: 'Disponibilidad y caídas', category: 'maintenance' },
    mainwp: { label: 'MainWP', icon: Wrench, blurb: 'Actualizaciones y plugins', category: 'maintenance' },
    site_agent: { label: 'Agente Imagina', icon: Bot, blurb: 'Respaldos y salud de WordPress', category: 'maintenance' },
    pagespeed: { label: 'Velocidad del sitio', icon: Gauge, blurb: 'Nota de velocidad de Google y Core Web Vitals', category: 'maintenance' },
    woocommerce: { label: 'WooCommerce', icon: ShoppingCart, blurb: 'Ventas y pedidos', category: 'sales' },
    database: { label: 'Base de datos', icon: Database, blurb: 'Consultas agregadas', category: 'custom' },
    endpoint: { label: 'Endpoint / CSV', icon: Link2, blurb: 'Cualquier API o CSV', category: 'custom' },
};

export const SOURCE_CATEGORIES: { key: SourceCategory; label: string }[] = [
    { key: 'analytics', label: 'Tráfico y marketing' },
    { key: 'ads', label: 'Publicidad' },
    { key: 'security', label: 'Seguridad' },
    { key: 'maintenance', label: 'Mantenimiento y disponibilidad' },
    { key: 'sales', label: 'Ventas' },
    { key: 'custom', label: 'Datos propios' },
];

export function sourceLabel(type: string, fallback?: string): string {
    return fallback ?? SOURCE_META[type]?.label ?? type;
}
