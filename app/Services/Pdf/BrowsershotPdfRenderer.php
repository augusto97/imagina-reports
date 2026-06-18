<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Spatie\Browsershot\Browsershot;

/**
 * Production PDF renderer: headless Chromium via Spatie Browsershot, printing the
 * same React report page the portal shows (single source of truth, §10.7). Waits
 * for `window.reportReady === true` so every block has finished rendering (§11.4).
 */
final class BrowsershotPdfRenderer implements PdfRenderer
{
    public function render(string $url): string
    {
        $browsershot = Browsershot::url($url)
            ->waitUntilNetworkIdle()
            ->waitForFunction('window.reportReady === true');

        $chromePath = config('services.browsershot.chrome_path');

        if (is_string($chromePath) && $chromePath !== '') {
            $browsershot->setChromePath($chromePath);
        }

        return $browsershot->pdf();
    }
}
