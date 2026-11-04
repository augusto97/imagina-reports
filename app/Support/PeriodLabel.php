<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A report period the way a client says it: "octubre 2026" for a calendar month, "1–15 oct
 * 2026" or "28 sep – 4 oct 2026" otherwise — instead of "01/10/2026 – 31/10/2026".
 */
final class PeriodLabel
{
    public static function for(DateTimeInterface $start, DateTimeInterface $end, string $locale = 'es'): string
    {
        $lang = strtolower(substr(str_replace('_', '-', $locale), 0, 2));
        $from = Carbon::instance($start);
        $from->locale($lang === '' ? 'es' : $lang);
        $to = Carbon::instance($end);
        $to->locale($lang === '' ? 'es' : $lang);

        $wholeMonth = $from->isSameDay($from->copy()->startOfMonth()) && $to->isSameDay($from->copy()->endOfMonth());
        if ($wholeMonth) {
            return $from->translatedFormat('F Y');
        }

        $short = static fn (Carbon $date): string => rtrim($date->translatedFormat('j M'), '.');

        if ($from->isSameMonth($to)) {
            return $from->day.'–'.$short($to).' '.$to->year;
        }

        return $short($from).($from->year !== $to->year ? ' '.$from->year : '').' – '.$short($to).' '.$to->year;
    }
}
