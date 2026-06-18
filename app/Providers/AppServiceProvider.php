<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AiClient;
use App\Ai\AnthropicAiClient;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderer;
use App\Services\Update\Deployer;
use App\Services\Update\SymlinkDeployer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request/job lifecycle (CLAUDE.md §5).
        $this->app->singleton(TenantContext::class);

        // Headless-Chromium PDF rendering (CLAUDE.md §10.7); faked in tests.
        $this->app->bind(PdfRenderer::class, BrowsershotPdfRenderer::class);

        // AI report builder backend (CLAUDE.md §10.6, Claude API); faked in tests.
        $this->app->bind(AiClient::class, AnthropicAiClient::class);

        // Self-updater deployer (CLAUDE.md §12); faked in tests — never swaps real symlinks in CI.
        $this->app->bind(Deployer::class, SymlinkDeployer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API resources return a flat top-level object (no "data" envelope) — the
        // shape the SPAs consume directly.
        JsonResource::withoutWrapping();
    }
}
