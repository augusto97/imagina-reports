<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Services\ReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePdfRenderer;
use Tests\TestCase;

class ReportPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_public_url_and_stores_the_pdf(): void
    {
        Storage::fake();
        $renderer = new FakePdfRenderer('%PDF-1.4 fake');
        $report = Report::factory()->create();

        $path = (new ReportPdfService($renderer))->generate($report);

        $this->assertSame("reports/{$report->public_token}.pdf", $path);
        Storage::assertExists($path);
        $this->assertSame($path, $report->fresh()?->pdf_path);

        // It rendered the report's own public page (single source of truth).
        $this->assertStringContainsString($report->public_token, (string) $renderer->lastUrl);
    }
}
