<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Connectors\Support\ParsesValues;

/**
 * Formats an outbound event into a human Slack message (CLAUDE.md §8). Only the events worth
 * a proactive ping to the team are formatted — anomalies, a sent report, an upsell signal;
 * everything else returns null so Slack stays signal, not noise.
 */
final class SlackMessage
{
    use ParsesValues;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function format(string $event, array $payload): ?string
    {
        return match ($event) {
            'anomaly.detected' => $this->anomaly($payload),
            'report.sent' => '✅ Reporte enviado'.$this->reportRef($payload).'.',
            'upsell.detected' => '💡 Oportunidad de venta detectada'.$this->siteRef($payload).'.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function anomaly(array $payload): string
    {
        $anomaly = $this->arrayOf($payload['anomaly'] ?? []);
        $metric = $this->toStr($anomaly['metric'] ?? 'una métrica');
        $change = $this->toFloat($anomaly['change_percent'] ?? 0);
        $arrow = $change >= 0 ? '▲' : '▼';

        return sprintf(
            '⚠️ Anomalía detectada en *%s*: %s %s%% (de %s a %s)%s.',
            $metric,
            $arrow,
            number_format(abs($change), 1),
            $this->num($anomaly['previous'] ?? 0),
            $this->num($anomaly['current'] ?? 0),
            $this->reportRef($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reportRef(array $payload): string
    {
        $id = $payload['report_id'] ?? null;

        return is_numeric($id) ? ' (reporte #'.(int) $id.')' : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function siteRef(array $payload): string
    {
        $id = $payload['site_id'] ?? null;

        return is_numeric($id) ? ' (sitio #'.(int) $id.')' : '';
    }

    private function num(mixed $value): string
    {
        return number_format($this->toFloat($value), $this->toFloat($value) === floor($this->toFloat($value)) ? 0 : 2);
    }
}
