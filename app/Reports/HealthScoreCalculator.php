<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * The 0–100 health score (CLAUDE.md §10.5): a weighted average of the signals the site's
 * sources actually provide. It **re-weights when a signal is missing** — a client is never
 * penalised for a source they don't have — and every signal comes with a plain-language detail,
 * so the report can say what the number is made of instead of showing a mystery score.
 *
 * Signals (weight · how it's scored · where it comes from):
 * - Disponibilidad   25 % · 99,9 % up → 98, 99 % → 80, 95 % or less → 0          · Better Stack
 * - Actualizaciones  20 % · −5 per pending update                                 · MainWP or the Agent
 * - Seguridad        20 % · malware → 40 max; −15 per vulnerability; −10 per abandoned plugin · MainWP / Agent
 * - Certificado SSL  10 % · ≥ 30 days 100; 14–29: 70; 7–13: 40; < 7: 0            · MainWP or the Agent
 * - Respaldos        10 % · last backup ≤ 1 day 100; ≤ 7: 90; ≤ 14: 60; ≤ 30: 30; older: 0 · the Agent
 * - Rendimiento      15 % · % of requests served from Cloudflare's cache (0–100)    · Cloudflare
 *
 * With no signal at all there is nothing to measure: the score stays 100 (so nothing reads as
 * a problem) but `signals` comes back empty and the block says so instead of claiming «excelente».
 *
 * @phpstan-type MetricBags array<string, array<array-key, mixed>>
 * @phpstan-type Signal array{key: string, label: string, detail: string, score: int, weight: int}
 */
final class HealthScoreCalculator
{
    /**
     * @param  MetricBags  $bags  source key => normalized metric bag
     */
    public function calculate(array $bags): int
    {
        return $this->breakdown($bags)['score'];
    }

    /**
     * The score and what it's made of.
     *
     * @param  MetricBags  $bags  source key => normalized metric bag
     * @return array{score: int, signals: list<Signal>}
     */
    public function breakdown(array $bags): array
    {
        $signals = array_values(array_filter([
            $this->uptime($bags),
            $this->updates($bags),
            $this->security($bags),
            $this->ssl($bags),
            $this->backups($bags),
            $this->performance($bags),
        ]));

        $weights = array_sum(array_column($signals, 'weight'));
        if ($weights === 0) {
            return ['score' => 100, 'signals' => []];
        }

        $weighted = array_sum(array_map(static fn (array $signal): int => $signal['score'] * $signal['weight'], $signals));

        return ['score' => (int) round($weighted / $weights), 'signals' => $signals];
    }

    /**
     * @param  MetricBags  $bags
     * @return Signal|null
     */
    private function uptime(array $bags): ?array
    {
        $value = $this->metric($bags, ['betteruptime.uptime_percent']);
        if ($value === null) {
            return null;
        }
        $value = $this->clamp($value, 0, 100);

        return $this->signal('uptime', 'Disponibilidad', $this->number($value, 2).' % del tiempo en línea', 100 - (100 - $value) * 20, 25);
    }

    /**
     * @param  MetricBags  $bags
     * @return Signal|null
     */
    private function updates(array $bags): ?array
    {
        $pending = $this->metric($bags, ['mainwp.updates_available', 'site_agent.updates_pending']);
        if ($pending === null) {
            return null;
        }
        $pending = (int) $pending;

        return $this->signal('updates', 'Actualizaciones', match (true) {
            $pending === 0 => 'Todo al día',
            $pending === 1 => '1 actualización pendiente',
            default => "{$pending} actualizaciones pendientes",
        }, 100 - $pending * 5, 20);
    }

    /**
     * @param  MetricBags  $bags
     * @return Signal|null
     */
    private function security(array $bags): ?array
    {
        $malware = $this->metric($bags, ['mainwp.malware_found']);
        $vulnerabilities = $this->metric($bags, ['mainwp.vulnerabilities_count']);
        $abandoned = $this->metric($bags, ['site_agent.abandoned_count']);
        if ($malware === null && $vulnerabilities === null && $abandoned === null) {
            return null;
        }

        $score = 100 - (int) ($vulnerabilities ?? 0) * 15 - (int) ($abandoned ?? 0) * 10;
        if (($malware ?? 0) > 0) {
            $score = min($score, 40);
        }

        $issues = array_filter([
            ($malware ?? 0) > 0 ? 'malware detectado' : null,
            ($vulnerabilities ?? 0) > 0 ? (int) $vulnerabilities.' '.((int) $vulnerabilities === 1 ? 'vulnerabilidad' : 'vulnerabilidades') : null,
            ($abandoned ?? 0) > 0 ? (int) $abandoned.' '.((int) $abandoned === 1 ? 'plugin abandonado' : 'plugins abandonados') : null,
        ]);

        return $this->signal('security', 'Seguridad', $issues === [] ? 'Sin malware ni puntos débiles conocidos' : ucfirst(implode(', ', $issues)), $score, 20);
    }

    /**
     * @param  MetricBags  $bags
     * @return Signal|null
     */
    private function ssl(array $bags): ?array
    {
        $days = $this->metric($bags, ['mainwp.ssl_days_remaining', 'site_agent.ssl_days_remaining']);
        if ($days === null) {
            return null;
        }
        $days = (int) $days;

        return $this->signal('ssl', 'Certificado SSL', $days <= 0 ? 'Vencido' : "Vence en {$days} días", match (true) {
            $days >= 30 => 100,
            $days >= 14 => 70,
            $days >= 7 => 40,
            default => 0,
        }, 10);
    }

    /**
     * @param  MetricBags  $bags
     * @return Signal|null
     */
    private function backups(array $bags): ?array
    {
        $days = $this->metric($bags, ['site_agent.last_backup_days']);
        if ($days === null) {
            return null;
        }
        $days = (int) $days;

        return $this->signal('backups', 'Respaldos', match (true) {
            $days <= 0 => 'Último respaldo: hoy',
            $days === 1 => 'Último respaldo: ayer',
            default => "Último respaldo hace {$days} días",
        }, match (true) {
            $days <= 1 => 100,
            $days <= 7 => 90,
            $days <= 14 => 60,
            $days <= 30 => 30,
            default => 0,
        }, 10);
    }

    /**
     * Cloudflare's cache ratio as a performance proxy. The connector stores it as a percentage
     * (0–100), not a fraction — reading it as 0–1 made any ratio of 1 % or more score 100.
     *
     * @param  MetricBags  $bags
     * @return Signal|null
     */
    private function performance(array $bags): ?array
    {
        $ratio = $this->metric($bags, ['cloudflare.cache_ratio']);
        if ($ratio === null) {
            return null;
        }
        $percent = $this->clamp($ratio, 0, 100);

        return $this->signal('performance', 'Rendimiento', $this->number($percent, 0).' % servido desde la caché (carga más rápido)', $percent, 15);
    }

    /**
     * @return Signal
     */
    private function signal(string $key, string $label, string $detail, float|int $score, int $weight): array
    {
        return ['key' => $key, 'label' => $label, 'detail' => $detail, 'score' => (int) round($this->clamp((float) $score, 0, 100)), 'weight' => $weight];
    }

    /**
     * The first of these metrics any source reported, as a number.
     *
     * @param  MetricBags  $bags
     * @param  list<string>  $keys  «source.metric» keys, in order of preference
     */
    private function metric(array $bags, array $keys): ?float
    {
        foreach ($keys as $key) {
            $source = strstr($key, '.', true);
            $value = $source !== false && is_array($bags[$source] ?? null) ? ($bags[$source][$key] ?? null) : null;
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function number(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, ',', '.');

        // «99,90» → «99,9», «100,00» → «100», but «60» stays «60».
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
