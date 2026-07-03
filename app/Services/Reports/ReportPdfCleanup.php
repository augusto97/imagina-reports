<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Report;
use App\Models\ReportDefinition;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the stored PDF files of reports about to be removed by a cascade delete. DB-level
 * FK cascades (definition→reports, site→definition→reports) bypass Eloquent model events, so
 * `ir_reports.pdf_path` files would otherwise be orphaned on disk forever when a definition or
 * site is deleted (FUN — PDF orphan cleanup). Called just before the delete, inside the
 * tenant-bound request, so the queries are agency-scoped.
 */
final class ReportPdfCleanup
{
    /**
     * @param  list<int>  $definitionIds
     */
    public static function forDefinitions(array $definitionIds): void
    {
        if ($definitionIds === []) {
            return;
        }

        $paths = Report::query()
            ->whereIn('report_definition_id', $definitionIds)
            ->whereNotNull('pdf_path')
            ->pluck('pdf_path')
            ->all();

        self::deletePaths($paths);
    }

    public static function forSite(int $siteId): void
    {
        $definitionIds = [];
        foreach (ReportDefinition::query()->where('site_id', $siteId)->pluck('id')->all() as $id) {
            if (is_numeric($id)) {
                $definitionIds[] = (int) $id;
            }
        }

        self::forDefinitions($definitionIds);
    }

    /**
     * @param  array<array-key, mixed>  $paths
     */
    private static function deletePaths(array $paths): void
    {
        $clean = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $clean[] = $path;
            }
        }

        if ($clean !== []) {
            Storage::delete($clean);
        }
    }
}
