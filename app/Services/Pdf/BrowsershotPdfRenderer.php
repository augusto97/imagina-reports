<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Spatie\Browsershot\Browsershot;

/**
 * Production PDF renderer: prints the React report page with **Browsershot**
 * (Puppeteer driving headless Chromium), via Node.
 *
 * Why Browsershot over driving the Chromium binary directly: the report SPA fetches
 * its data and renders every block asynchronously, then sets `window.reportReady`
 * (CLAUDE.md §11.4). Browsershot's `waitForFunction('window.reportReady === true')`
 * waits for that exact signal — a deterministic wait instead of a fixed virtual-time
 * budget that prints too early (truncated) or too late. Puppeteer also manages the
 * Chrome handshake, so we sidestep the Ubuntu snap / open_basedir binary-probing
 * the direct-CLI renderer needed.
 *
 * Runtime requirements (the locked "no build on server" decision still holds —
 * assets are built in CI; this only needs Node + puppeteer *present*):
 *   - Node.js on the VPS (`BROWSERSHOT_NODE_PATH`, default /usr/bin/node).
 *   - The FULL `puppeteer` package available — Browsershot's bin/browser.cjs does
 *     `require('puppeteer')`, so puppeteer-core is NOT enough. Install it with
 *     `PUPPETEER_SKIP_DOWNLOAD=true npm install puppeteer` (skips its bundled Chromium)
 *     into a node_modules dir pointed to by BROWSERSHOT_NODE_MODULE_PATH.
 *   - A real (non-snap) Chrome/Chromium binary (`BROWSERSHOT_CHROME_PATH`), required
 *     since the bundled Chromium download is skipped.
 */
final class BrowsershotPdfRenderer implements PdfRenderer
{
    /** How long to wait (ms) for the SPA to set window.reportReady before printing. */
    private const READY_TIMEOUT_MS = 30000;

    public function render(string $url): string
    {
        $browsershot = Browsershot::url($url)
            ->noSandbox()
            ->waitForFunction('window.reportReady === true', timeout: self::READY_TIMEOUT_MS)
            ->showBackground()
            ->format('A4')
            ->margins(10, 10, 10, 10)
            ->timeout(120);

        $this->configurePaths($browsershot);

        return $browsershot->pdf();
    }

    /**
     * Point Browsershot at the server's Node, Chrome, and node_modules. Each is only
     * applied when configured so local/dev (where they're on PATH) keeps working.
     */
    private function configurePaths(Browsershot $browsershot): void
    {
        $config = config('services.browsershot');

        $node = is_array($config) ? ($config['node_path'] ?? null) : null;
        if (is_string($node) && $node !== '') {
            $browsershot->setNodeBinary($node);
        }

        $npm = is_array($config) ? ($config['npm_path'] ?? null) : null;
        if (is_string($npm) && $npm !== '') {
            $browsershot->setNpmBinary($npm);
        }

        $chrome = is_array($config) ? ($config['chrome_path'] ?? null) : null;
        if (is_string($chrome) && $chrome !== '') {
            $browsershot->setChromePath($chrome);
        }

        $modules = is_array($config) ? ($config['node_module_path'] ?? null) : null;
        if (is_string($modules) && $modules !== '') {
            $browsershot->setNodeModulePath($modules);
        }
    }
}
