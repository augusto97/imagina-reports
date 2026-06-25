<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The extensible set of data source types (CLAUDE.md §5/§9). Adding a source
 * later is a new case here + a new connector class — no schema refactor.
 * Stored as a string column on ir_data_sources.
 */
enum DataSourceType: string
{
    case MainWp = 'mainwp';
    case Ga4 = 'ga4';
    case Gsc = 'gsc';
    case Cloudflare = 'cloudflare';
    case CrowdSec = 'crowdsec';
    case Virusdie = 'virusdie';
    case BetterUptime = 'betteruptime';
    case WooCommerce = 'woocommerce';
    case Database = 'database';
    case Endpoint = 'endpoint';
    case SiteAgent = 'site_agent';

    public function label(): string
    {
        return match ($this) {
            self::MainWp => 'MainWP',
            self::Ga4 => 'Google Analytics 4',
            self::Gsc => 'Google Search Console',
            self::Cloudflare => 'Cloudflare',
            self::CrowdSec => 'CrowdSec',
            self::Virusdie => 'Virusdie',
            self::BetterUptime => 'Better Stack (Uptime)',
            self::WooCommerce => 'WooCommerce',
            self::Database => 'Database',
            self::Endpoint => 'Endpoint / CSV',
            self::SiteAgent => 'Agente Imagina (sitio)',
        };
    }
}
