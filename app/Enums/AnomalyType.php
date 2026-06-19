<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of anomaly the report engine raises (CLAUDE.md §13): a meaningful
 * traffic drop or a security attack spike, detected by comparing a period against
 * the previous one. Emitted as internal alerts and `anomaly.detected` webhooks (§8).
 */
enum AnomalyType: string
{
    case TrafficDrop = 'traffic_drop';
    case AttackSpike = 'attack_spike';

    public function label(): string
    {
        return match ($this) {
            self::TrafficDrop => 'Traffic drop',
            self::AttackSpike => 'Attack spike',
        };
    }
}
