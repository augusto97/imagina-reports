<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Connectors\Support\ParsesValues;

/**
 * Formats an outbound event into a human Slack message (CLAUDE.md §8). Only the events worth
 * a proactive ping to the team are formatted — anomalies, a sent report, an upsell signal, a
 * connection that is connected, fails, works again or is removed; everything else returns null so
 * Slack stays signal, not noise.
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
            'report.question' => $this->question($payload),
            'source.failed' => sprintf(
                '🔌 %s dejó de funcionar en *%s*: %s%s',
                $this->toStr($payload['label'] ?? 'Una fuente'),
                $this->toStr($payload['site'] ?? 'un sitio'),
                $this->toStr($payload['error'] ?? ''),
                ($payload['reconnectable'] ?? false) === true ? ' — puedes pedirle al cliente que la reconecte desde el panel.' : '',
            ),
            'source.connected' => sprintf(
                '🔗 %s %s %s en *%s*.',
                $this->toStr($payload['by'] ?? '') !== '' ? $this->toStr($payload['by']) : 'Alguien',
                ($payload['again'] ?? false) === true ? 'volvió a conectar' : 'conectó',
                $this->toStr($payload['label'] ?? 'una fuente'),
                $this->toStr($payload['site'] ?? 'un sitio'),
            ),
            'source.recovered' => sprintf(
                '✅ %s volvió a funcionar en *%s*.',
                $this->toStr($payload['label'] ?? 'Una fuente'),
                $this->toStr($payload['site'] ?? 'un sitio'),
            ),
            'source.removed' => sprintf(
                '🗑️ %s quitó %s de *%s*.',
                $this->toStr($payload['by'] ?? '') !== '' ? $this->toStr($payload['by']) : 'Alguien',
                $this->toStr($payload['label'] ?? 'una fuente'),
                $this->toStr($payload['site'] ?? 'un sitio'),
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function question(array $payload): string
    {
        $author = $this->toStr($payload['author'] ?? '');
        $site = $this->toStr($payload['site'] ?? '');

        return sprintf(
            '💬 %s preguntó sobre el informe%s: «%s»',
            $author !== '' ? '*'.$author.'*' : 'Un cliente',
            $site !== '' ? ' de *'.$site.'*' : $this->reportRef($payload),
            $this->toStr($payload['excerpt'] ?? ''),
        );
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
