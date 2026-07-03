<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AiClient;
use App\Ai\AnthropicAiClient;
use App\Events\ReportGenerated;
use App\Listeners\DetectReportAnomalies;
use App\Listeners\DetectUpsellOpportunities;
use App\Listeners\ReportWebhookSubscriber;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderer;
use App\Services\Update\Deployer;
use App\Services\Update\SymlinkDeployer;
use App\Services\Webhooks\HttpWebhookDispatcher;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Http\SsrfGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request/job lifecycle (CLAUDE.md §5).
        $this->app->singleton(TenantContext::class);

        // Browsershot (Puppeteer) PDF rendering (CLAUDE.md §10.7); faked in tests.
        $this->app->bind(PdfRenderer::class, BrowsershotPdfRenderer::class);

        // AI report builder backend (CLAUDE.md §10.6, Claude API); faked in tests.
        $this->app->bind(AiClient::class, AnthropicAiClient::class);

        // Self-updater deployer (CLAUDE.md §12); faked in tests — never swaps real symlinks in CI.
        $this->app->bind(Deployer::class, SymlinkDeployer::class);

        // Outbound webhook delivery (CLAUDE.md §8); queues per-endpoint HTTP jobs.
        $this->app->bind(WebhookDispatcher::class, HttpWebhookDispatcher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API resources return a flat top-level object (no "data" envelope) — the
        // shape the SPAs consume directly.
        JsonResource::withoutWrapping();

        // Report lifecycle → webhooks + anomaly/upsell detection (CLAUDE.md §8/§13).
        Event::listen(ReportGenerated::class, DetectReportAnomalies::class);
        Event::listen(ReportGenerated::class, DetectUpsellOpportunities::class);
        Event::subscribe(ReportWebhookSubscriber::class);

        $this->guardOutboundHttp();
        $this->configurePublicRateLimiter();
    }

    /**
     * Rate limit the token-authenticated public surface (portal / dashboard) to blunt a cheap
     * DoS — each request re-resolves a report from snapshots (SEC-7). The server's own PDF
     * render loads these same endpoints from its OWN IP, so a naive per-IP limit would throttle
     * a month-start batch of PDFs; those requests carry a valid print token, so they're exempt.
     */
    private function configurePublicRateLimiter(): void
    {
        RateLimiter::for('public-report', function (Request $request): Limit {
            if ($this->isInternalPrintRender($request)) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->ip() ?? 'unknown');
        });
    }

    /**
     * True when the request is the server's own headless-Chromium PDF render: it carries the
     * per-report print token (derived from the route's public token + the app key), which only
     * the server can produce. Cheap to check — no DB lookup.
     */
    private function isInternalPrintRender(Request $request): bool
    {
        $provided = $request->header('X-Print-Token') ?: $request->query('print');
        $token = $request->route('token');

        if (! is_string($provided) || $provided === '' || ! is_string($token)) {
            return false;
        }

        $key = config('app.key');
        $expected = hash_hmac('sha256', 'print:'.$token, is_string($key) ? $key : '');

        return hash_equals($expected, $provided);
    }

    /**
     * SSRF defense for every outbound HTTP-client request (connectors, webhooks): reject a
     * request whose host resolves to a private/reserved/loopback address. Registered once
     * here so it covers all connectors without per-connector wiring. Skipped in tests (which
     * fake HTTP) so the suite never does real DNS.
     */
    private function guardOutboundHttp(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            if (SsrfGuard::isBlockedUrl((string) $request->getUri())) {
                throw new ConnectionException('Blocked request to a private or reserved address (SSRF guard).');
            }

            return $request;
        });
    }
}
