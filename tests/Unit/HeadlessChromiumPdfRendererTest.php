<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pdf\HeadlessChromiumPdfRenderer;
use RuntimeException;
use Tests\TestCase;

class HeadlessChromiumPdfRendererTest extends TestCase
{
    public function test_it_throws_a_clear_error_when_no_pdf_is_produced(): void
    {
        // `/bin/true` stands in for the Chromium binary: it exits 0 but writes no
        // PDF, so the renderer must surface a failure rather than return junk.
        config(['services.browsershot.chrome_path' => '/bin/true']);

        $this->expectException(RuntimeException::class);

        (new HeadlessChromiumPdfRenderer)->render('https://example.test/report');
    }
}
