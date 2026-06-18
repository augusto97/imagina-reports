<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Report;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a report to PDF and stores it (CLAUDE.md §10.7). The renderer loads the
 * internal print route for the report's public token, so the PDF is pixel-identical
 * to the portal. Stored path is saved to ir_reports.pdf_path.
 */
final readonly class ReportPdfService
{
    public function __construct(private PdfRenderer $renderer) {}

    public function generate(Report $report): string
    {
        $url = route('report.public', ['token' => $report->public_token]);

        $pdf = $this->renderer->render($url);

        $path = "reports/{$report->public_token}.pdf";
        Storage::put($path, $pdf);

        $report->forceFill(['pdf_path' => $path])->save();

        return $path;
    }
}
