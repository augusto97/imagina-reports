<?php

declare(strict_types=1);

return [

    // Anomaly detection (CLAUDE.md §13). Thresholds are config-driven so the rules
    // can be tuned per environment without code changes. Detection compares the
    // report's period against the equal-length previous period (Period::previous()).

    // Traffic drop: flag when the watched metric falls by at least drop_pct, but
    // only when the previous period had a meaningful baseline (min_previous) — so a
    // tiny site bouncing from 3 to 1 visits never raises noise.
    'traffic_drop' => [
        'metric' => env('ANOMALY_TRAFFIC_METRIC', 'ga4.sessions'),
        'min_previous' => (float) env('ANOMALY_TRAFFIC_MIN_PREVIOUS', 100),
        'drop_pct' => (float) env('ANOMALY_TRAFFIC_DROP_PCT', 30),
    ],

    // Attack spike: flag when any watched security metric rises by at least
    // spike_pct vs a meaningful baseline, OR jumps from a near-zero baseline to at
    // least min_current absolute events.
    'attack_spike' => [
        'metrics' => ['cloudflare.threats_blocked', 'crowdsec.attacks_blocked'],
        'min_previous' => (float) env('ANOMALY_ATTACK_MIN_PREVIOUS', 10),
        'min_current' => (float) env('ANOMALY_ATTACK_MIN_CURRENT', 50),
        'spike_pct' => (float) env('ANOMALY_ATTACK_SPIKE_PCT', 100),
    ],

];
