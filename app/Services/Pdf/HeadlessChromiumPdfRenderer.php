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
 * Snap caveat: on Ubuntu the default `chromium` is a *snap*, which snapd refuses to
 * launch from a non-snap service like the web server ("is not a snap cgroup"). So we
 * probe a list of real (non-snap) binaries — Google Chrome first — and fall back
 * across them, surfacing install guidance if only the snap is present.
 *
 * `--virtual-time-budget` lets the SPA fetch its data and render every block (and
 * the charts settle) before the page is printed — the CLI equivalent of waiting
 * for `window.reportReady` (§11.4).
 */
final class HeadlessChromiumPdfRenderer implements PdfRenderer
{
    /** Real, non-snap binaries to try in order when no working path is configured. */
    private const CANDIDATES = [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/opt/google/chrome/chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ];

    public function render(string $url): string
    {
        $errors = [];

        foreach ($this->binaries() as $binary) {
            try {
                return $this->printWith($binary, $url);
            } catch (\Throwable $e) {
                $errors[$binary] = $e->getMessage();
            }
        }

        throw new RuntimeException($this->explain($errors));
    }

    /**
     * @return list<string> Chromium/Chrome paths to attempt, configured first.
     *
     * We deliberately do NOT stat these with is_executable(): ServerAvatar sets PHP's
     * open_basedir to the app dir + /tmp, so stating /usr/bin/* raises a warning and
     * would (with the error suppressed) hide the real Chrome. open_basedir restricts
     * PHP file ops, not proc_open — so we just try to launch each and let a missing
     * binary fail fast, falling through to the next.
     */
    private function binaries(): array
    {
        $configured = config('services.browsershot.chrome_path');
        $paths = is_string($configured) && $configured !== '' ? [$configured] : [];

        foreach (self::CANDIDATES as $candidate) {
            if (! in_array($candidate, $paths, true)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    private function printWith(string $binary, string $url): string
    {
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

                throw new RuntimeException($reason !== '' ? $reason : 'no se generó el PDF');
            }

            return (string) file_get_contents($outputFile);
        } finally {
            @unlink($outputFile);
            File::deleteDirectory($profileDir);
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function explain(array $errors): string
    {
        if ($errors === []) {
            return 'No se encontró ningún binario de Chrome/Chromium en el servidor. '.$this->installHint();
        }

        $detail = implode(' | ', array_map(static fn (string $bin, string $err): string => "{$bin}: {$err}", array_keys($errors), $errors));

        // The snap cgroup error means the only Chromium present is a confined snap,
        // which the web server can't launch — point them at a real package.
        if (str_contains($detail, 'snap') || str_contains($detail, 'cgroup')) {
            return 'Tu Chromium está instalado como snap y el servidor web no puede ejecutarlo. '.$this->installHint().' Detalle: '.$detail;
        }

        return 'No se pudo generar el PDF con Chromium. Detalle: '.$detail;
    }

    private function installHint(): string
    {
        return 'Instala Google Chrome (no-snap) en el VPS y apunta BROWSERSHOT_CHROME_PATH a él: '
            .'`wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb && '
            .'sudo apt install -y ./google-chrome-stable_current_amd64.deb` '
            .'→ BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome-stable';
    }
}
