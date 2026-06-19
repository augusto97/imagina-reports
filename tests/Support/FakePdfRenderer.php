<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Pdf\PdfRenderer;

/**
 * Stub renderer so PDF tests never launch Chromium.
 */
final class FakePdfRenderer implements PdfRenderer
{
    public ?string $lastUrl = null;

    public function __construct(private readonly string $content = '%PDF-1.4 fake') {}

    public function render(string $url): string
    {
        $this->lastUrl = $url;

        return $this->content;
    }
}
