<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Production PDF renderer: prints the React report page with **headless Chromium
 * directly** (`--headless --print-to-pdf`), via Symfony Process.
 *
 * Why not Browsershot/Puppeteer: Node.js is intentionally NOT installed on the
 * server (CLAUDE.md §2 — SPAs are built in CI). Browsershot shells out to `node`,
 * so it can't run here. Driving the Chromium binary itself needs no Node at all.
 *
 * `--virtual-time-budget` lets the SPA fetch its data and render every block (and
 * the charts settle) before the page is printed — the CLI equivalent of waiting
 * for `window.reportReady` (§11.4).
 */
final class HeadlessChromiumPdfRenderer implements PdfRenderer
{
    public function render(string $url): string
    {
        $binary = config('services.browsershot.chrome_path');
        $binary = is_string($binary) && $binary !== '' ? $binary : 'chromium';

        $token = bin2hex(random_bytes(8));
        $profileDir = sys_get_temp_dir()."/ir-chromium-{$token}";
        $outputFile = sys_get_temp_dir()."/ir-report-{$token}.pdf";

        $process = new Process([
            $binary,
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--no-pdf-header-footer',
            '--run-all-compositor-stages-before-draw',
            '--virtual-time-budget=20000',
            "--user-data-dir={$profileDir}",
            "--print-to-pdf={$outputFile}",
            $url,
        ]);
        $process->setTimeout(120);

        try {
            $process->run();

            // Chromium can exit non-zero while still printing (font/GPU warnings), so
            // trust the artifact: a non-empty PDF on disk means success.
            if (! is_file($outputFile) || filesize($outputFile) === 0) {
                $reason = trim($process->getErrorOutput()) ?: trim($process->getOutput());

                throw new RuntimeException(
                    $reason !== '' ? $reason : "Chromium ({$binary}) no generó el PDF. Revisa la ruta del binario y los permisos."
                );
            }

            return (string) file_get_contents($outputFile);
        } finally {
            @unlink($outputFile);
            File::deleteDirectory($profileDir);
        }
    }
}
