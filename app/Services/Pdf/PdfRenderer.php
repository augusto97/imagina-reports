<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Renders a URL to a PDF. Abstracted so the report PDF pipeline (CLAUDE.md §10.7)
 * can run headless Chromium in production while tests fake it (no Chromium in CI).
 */
interface PdfRenderer
{
    /**
     * @return string Raw PDF bytes.
     */
    public function render(string $url): string;
}
