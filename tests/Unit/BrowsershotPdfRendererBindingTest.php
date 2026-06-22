<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderer;
use Tests\TestCase;

/**
 * Locks the owner decision (2026-06-22) to render PDFs with Browsershot (Puppeteer +
 * window.reportReady) rather than driving the Chromium binary directly. The actual
 * render needs Node + Chrome, so it is exercised live on the VPS, not in CI.
 */
class BrowsershotPdfRendererBindingTest extends TestCase
{
    public function test_the_pdf_renderer_resolves_to_browsershot(): void
    {
        $this->assertInstanceOf(
            BrowsershotPdfRenderer::class,
            $this->app->make(PdfRenderer::class),
        );
    }
}
