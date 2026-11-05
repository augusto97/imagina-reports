<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Readable time-of-day and day-of-week series. Sources hand these back as bare numbers
 * («0»…«23», «0»…«6» with 0 = Sunday) in text order («1, 10, 11…»), so a chart of them read
 * as random numbers. Here they get their natural order (00 h → 23 h; Monday → Sunday) and a
 * label anyone reads («14 h», «Lun»). Applied when a block's value is resolved, so stored
 * snapshots don't need re-syncing and the editor preview and the report agree.
 */
final class SeriesLabels
{
    /** Short day names by locale, Sunday first (the sources' 0 = Sunday). */
    private const DAYS = [
        'es' => ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'],
        'en' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        'pt' => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
    ];

    /**
     * @return mixed the value, relabelled and reordered when it's an hour/weekday series
     */
    public static function apply(string $metricKey, mixed $value, string $locale = 'es'): mixed
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return $value;
        }

        return match (true) {
            str_ends_with($metricKey, '_by_hour') => self::relabel($value, 23, static fn (int $hour): string => sprintf('%02d h', $hour), static fn (int $hour): int => $hour),
            str_ends_with($metricKey, '_by_weekday') => self::relabel(
                $value,
                6,
                static fn (int $day): string => (self::DAYS[substr($locale, 0, 2)] ?? self::DAYS['es'])[$day],
                // Monday first, Sunday last.
                static fn (int $day): int => ($day + 6) % 7,
            ),
            default => $value,
        };
    }

    /**
     * @param  list<mixed>  $rows
     * @param  callable(int): string  $label
     * @param  callable(int): int  $order
     * @return list<mixed>
     */
    private static function relabel(array $rows, int $max, callable $label, callable $order): array
    {
        $field = null;
        $parsed = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return $rows;
            }
            $field ??= array_key_exists('date', $row) ? 'date' : (array_key_exists('label', $row) ? 'label' : null);
            $raw = $field !== null ? ($row[$field] ?? null) : null;
            // Only bare numbers in range: anything else (already labelled, real dates) is left as is.
            if ($field === null || ! (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,2}$/', $raw) === 1)) || (int) $raw > $max) {
                return $rows;
            }
            $parsed[] = ['n' => (int) $raw, 'row' => $row];
        }

        usort($parsed, static fn (array $a, array $b): int => $order($a['n']) <=> $order($b['n']));

        return array_map(static fn (array $entry): array => [...$entry['row'], $field => $label($entry['n'])], $parsed);
    }
}
