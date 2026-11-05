<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Support\PeriodLabel;

/**
 * The bits every report-related email shares: the agency's colour and logo, the site's name,
 * the period the way people say it, and the report's language.
 */
trait BrandsReportMail
{
    /** The report's accent (its theme, else the agency brand), only as a plain hex colour. */
    protected function accentFor(Report $report): string
    {
        $theme = $report->resolved_blocks['theme'] ?? null;
        foreach ([is_array($theme) ? ($theme['accent'] ?? null) : null, $report->agency?->brand_color] as $color) {
            if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
                return $color;
            }
        }

        return '#4f46e5';
    }

    protected function logoFor(?Agency $agency): ?string
    {
        $url = $agency?->logoUrl();
        if ($url === null || $url === '') {
            return null;
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }

    protected function siteNameFor(Report $report): string
    {
        $site = $report->definition?->site;

        return $site !== null ? $site->name : ($report->definition !== null ? $report->definition->name : '');
    }

    protected function periodFor(Report $report, string $locale): string
    {
        return PeriodLabel::for($report->period_start, $report->period_end, $locale);
    }

    /** es / en / pt_BR from the report's definition; anything else falls back to Spanish. */
    protected function localeFor(Report $report): string
    {
        $definition = $report->definition;
        $normalized = str_replace('-', '_', $definition instanceof ReportDefinition ? $definition->locale : 'es');

        return in_array($normalized, ['es', 'en', 'pt_BR'], true) ? $normalized : 'es';
    }
}
