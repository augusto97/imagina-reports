<?php

declare(strict_types=1);

return [

    // Upsell-opportunity detection (CLAUDE.md §13). Config-driven signals, read from
    // the same pre-aggregated snapshots as the report — surfaced to the agency
    // (internal alert + `upsell.detected` webhook), never shown to the client.

    // Sustained traffic growth → the site may need a bigger hosting/CDN plan.
    'traffic_growth' => [
        'metric' => env('UPSELL_TRAFFIC_METRIC', 'ga4.sessions'),
        'min_previous' => (float) env('UPSELL_TRAFFIC_MIN_PREVIOUS', 100),
        'growth_pct' => (float) env('UPSELL_TRAFFIC_GROWTH_PCT', 40),
    ],

    // Revenue growth → e-commerce optimization / conversion services.
    'sales_growth' => [
        'metric' => env('UPSELL_SALES_METRIC', 'woocommerce.revenue'),
        'min_previous' => (float) env('UPSELL_SALES_MIN_PREVIOUS', 100),
        'growth_pct' => (float) env('UPSELL_SALES_GROWTH_PCT', 25),
    ],

    // High sustained attack volume despite current protection → security hardening.
    'security_pressure' => [
        'metrics' => ['cloudflare.threats_blocked', 'crowdsec.attacks_blocked'],
        'min_total' => (float) env('UPSELL_SECURITY_MIN_TOTAL', 500),
    ],

    // Coverage gaps → propose connecting a service the site is not protected by yet.
    'coverage_gaps' => [
        'uptime' => ['source' => 'betteruptime'],
        'security' => ['sources' => ['cloudflare', 'crowdsec']],
    ],

];
