<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of upsell opportunity the report engine surfaces to the agency
 * (CLAUDE.md §13): commercial signals derived from the resolved metrics that
 * justify proposing a plan upgrade or a new service. Internal-only.
 */
enum UpsellType: string
{
    case TrafficGrowth = 'traffic_growth';
    case SalesGrowth = 'sales_growth';
    case SecurityHardening = 'security_hardening';
    case UptimeMonitoring = 'uptime_monitoring';
    case SecurityProtection = 'security_protection';

    public function label(): string
    {
        return match ($this) {
            self::TrafficGrowth => 'Traffic growth',
            self::SalesGrowth => 'Sales growth',
            self::SecurityHardening => 'Security hardening',
            self::UptimeMonitoring => 'Uptime monitoring',
            self::SecurityProtection => 'Security protection',
        };
    }
}
