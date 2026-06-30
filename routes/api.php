<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AiTemplateController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalculatedMetricController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ConnectorController;
use App\Http\Controllers\Api\V1\DataSourceController;
use App\Http\Controllers\Api\V1\Ga4DatasetController;
use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Controllers\Api\V1\MetricCatalogController;
use App\Http\Controllers\Api\V1\PreviewController;
use App\Http\Controllers\Api\V1\PublicDashboardController;
use App\Http\Controllers\Api\V1\PublicReportController;
use App\Http\Controllers\Api\V1\ReportCommentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportDefinitionController;
use App\Http\Controllers\Api\V1\ReportInsightsController;
use App\Http\Controllers\Api\V1\ReportNarrativeController;
use App\Http\Controllers\Api\V1\ReportSharingController;
use App\Http\Controllers\Api\V1\ReportTemplateController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SiteAgentController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SiteWorkLogController;
use App\Http\Controllers\Api\V1\SystemUpdateController;
use App\Http\Controllers\Api\V1\TrendsController;
use App\Http\Controllers\Api\V1\UpsellController;
use App\Http\Controllers\Api\V1\WorkLogController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api/v1 (see bootstrap/app.php).
| Resource endpoints are added phase by phase per CLAUDE.md §8.
|
*/

// Cheap, meaningful liveness probe used by the self-updater health check (CLAUDE.md §12.5).
Route::get('/health', static fn (): JsonResponse => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]))->name('api.health');

// Public, signed-token report data for the portal + PDF (no auth, CLAUDE.md §8).
Route::get('/public/reports/{token}', [PublicReportController::class, 'show'])
    ->name('api.public.reports.show');
Route::get('/public/reports/{token}/periods', [PublicReportController::class, 'periods'])
    ->name('api.public.reports.periods');

// Live dashboard data (Etapa D): a published definition re-resolved for a client-chosen
// date range, from stored snapshots only (CLAUDE.md §3.1).
Route::get('/public/dashboards/{token}', [PublicDashboardController::class, 'show'])
    ->name('api.public.dashboards.show');

// Push ingest (CLAUDE.md §9 — CrowdSec push model). Public: each client VPS POSTs its
// aggregated data outbound, authenticated by the source's per-source push token (no
// inbound port on the client server). Throttled to blunt token-guessing.
Route::post('/ingest/{token}', [IngestController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('api.ingest.store');

// SPA cookie-session login (CLAUDE.md §2). Stateful via statefulApi(); throttled.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('api.login');

// Authenticated, tenant-bound routes. `tenant` runs after `auth:sanctum` so the
// AgencyScope is active for everything inside (CLAUDE.md §5). Resource endpoints
// are added phase by phase per §8.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/user', [AuthController::class, 'me'])->name('api.user');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::put('/user/password', [AccountController::class, 'updatePassword'])->name('api.user.password');

    Route::get('connectors', [ConnectorController::class, 'index'])->name('api.connectors.index');

    // Agency settings: white-label branding + the AI builder's Anthropic key (§11.1).
    Route::get('agency', [AgencyController::class, 'show'])->name('api.agency.show');
    Route::put('agency', [AgencyController::class, 'update'])->name('api.agency.update');
    Route::post('agency/logo', [AgencyController::class, 'uploadLogo'])->name('api.agency.logo');
    Route::get('agency/retention/preview', [AgencyController::class, 'retentionPreview'])->name('api.agency.retention.preview');
    Route::post('agency/retention/prune', [AgencyController::class, 'pruneSnapshots'])->name('api.agency.retention.prune');
    // Agency-level reusable calculated metrics (§10.1).
    Route::put('agency/calculated-metrics', [CalculatedMetricController::class, 'update'])->name('api.agency.calculated-metrics.update');

    Route::get('clients', [ClientController::class, 'index'])->name('api.clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('api.clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('api.clients.show');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('api.clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('api.clients.destroy');

    Route::get('sites', [SiteController::class, 'index'])->name('api.sites.index');
    Route::post('sites', [SiteController::class, 'store'])->name('api.sites.store');
    Route::get('sites/{site}', [SiteController::class, 'show'])->name('api.sites.show');
    Route::put('sites/{site}', [SiteController::class, 'update'])->name('api.sites.update');
    Route::get('sites/{site}/snapshot-periods', [SiteController::class, 'snapshotPeriods'])->name('api.sites.snapshot-periods');

    // Fast day-to-day work logging per site (hours invested) — CLAUDE.md §11.5.
    Route::get('sites/{site}/work-logs', [SiteWorkLogController::class, 'index'])->name('api.sites.work-logs.index');
    Route::post('sites/{site}/work-logs', [SiteWorkLogController::class, 'store'])->name('api.sites.work-logs.store');
    Route::delete('work-logs/{workLog}', [SiteWorkLogController::class, 'destroy'])->name('api.work-logs.destroy');

    Route::get('sites/{site}/data-sources', [DataSourceController::class, 'index'])->name('api.sites.data-sources.index');
    Route::get('sites/{site}/data-sources/coverage', [DataSourceController::class, 'coverage'])->name('api.sites.data-sources.coverage');
    Route::post('sites/{site}/data-sources', [DataSourceController::class, 'store'])->name('api.sites.data-sources.store');
    Route::get('sites/{site}/metric-catalog', [MetricCatalogController::class, 'show'])->name('api.sites.metric-catalog');
    Route::post('sites/{site}/ai-template', [AiTemplateController::class, 'store'])->name('api.sites.ai-template');
    Route::post('sites/{site}/preview', [PreviewController::class, 'preview'])->name('api.sites.preview');
    Route::post('sites/{site}/calc-preview', [CalculatedMetricController::class, 'preview'])->name('api.sites.calc-preview');
    Route::put('sites/{site}/calculated-metrics', [CalculatedMetricController::class, 'updateSite'])->name('api.sites.calculated-metrics.update');
    Route::post('sites/{site}/sync', [PreviewController::class, 'sync'])->name('api.sites.sync');
    Route::put('data-sources/{dataSource}', [DataSourceController::class, 'update'])->name('api.data-sources.update');
    Route::delete('data-sources/{dataSource}', [DataSourceController::class, 'destroy'])->name('api.data-sources.destroy');
    Route::post('data-sources/{dataSource}/test', [DataSourceController::class, 'test'])->name('api.data-sources.test');
    // Self-serve GA4 dataset builder (§10.6/A.3): metadata dictionary + test/save/delete.
    Route::get('data-sources/{dataSource}/ga4/metadata', [Ga4DatasetController::class, 'metadata'])->name('api.data-sources.ga4.metadata');
    Route::post('data-sources/{dataSource}/ga4/datasets/test', [Ga4DatasetController::class, 'test'])->name('api.data-sources.ga4.datasets.test');
    Route::post('data-sources/{dataSource}/ga4/datasets', [Ga4DatasetController::class, 'store'])->name('api.data-sources.ga4.datasets.store');
    Route::delete('data-sources/{dataSource}/ga4/datasets/{key}', [Ga4DatasetController::class, 'destroy'])->name('api.data-sources.ga4.datasets.destroy');

    Route::get('report-templates/default-blocks', [ReportTemplateController::class, 'defaultBlocks'])->name('api.report-templates.default-blocks');
    Route::get('report-templates', [ReportTemplateController::class, 'index'])->name('api.report-templates.index');
    Route::post('report-templates', [ReportTemplateController::class, 'store'])->name('api.report-templates.store');
    Route::get('report-templates/{reportTemplate}', [ReportTemplateController::class, 'show'])->name('api.report-templates.show');
    Route::put('report-templates/{reportTemplate}', [ReportTemplateController::class, 'update'])->name('api.report-templates.update');
    Route::delete('report-templates/{reportTemplate}', [ReportTemplateController::class, 'destroy'])->name('api.report-templates.destroy');

    Route::get('report-definitions', [ReportDefinitionController::class, 'index'])->name('api.report-definitions.index');
    Route::post('report-definitions', [ReportDefinitionController::class, 'store'])->name('api.report-definitions.store');
    Route::get('report-definitions/{reportDefinition}', [ReportDefinitionController::class, 'show'])->name('api.report-definitions.show');
    Route::put('report-definitions/{reportDefinition}', [ReportDefinitionController::class, 'update'])->name('api.report-definitions.update');
    Route::delete('report-definitions/{reportDefinition}', [ReportDefinitionController::class, 'destroy'])->name('api.report-definitions.destroy');
    Route::put('report-definitions/{reportDefinition}/sharing', [ReportSharingController::class, 'update'])->name('api.report-definitions.sharing.update');
    Route::post('report-definitions/{reportDefinition}/sharing/dashboard-token', [ReportSharingController::class, 'rotateDashboardToken'])->name('api.report-definitions.sharing.dashboard-token');

    Route::get('schedules', [ScheduleController::class, 'index'])->name('api.schedules.index');
    Route::post('schedules', [ScheduleController::class, 'store'])->name('api.schedules.store');

    // Agency-wide trends + multi-client comparisons (CLAUDE.md §13).
    Route::get('trends', [TrendsController::class, 'index'])->name('api.trends.index');

    // Agency-wide upsell opportunities (CLAUDE.md §13) — internal-only commercial signals.
    Route::get('upsell', [UpsellController::class, 'index'])->name('api.upsell.index');

    Route::get('reports', [ReportController::class, 'index'])->name('api.reports.index');
    Route::post('reports/generate', [ReportController::class, 'generate'])->name('api.reports.generate');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('api.reports.show');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('api.reports.destroy');
    Route::get('reports/{report}/pdf', [ReportController::class, 'pdf'])->name('api.reports.pdf');
    Route::post('reports/{report}/approve', [ReportController::class, 'approve'])->name('api.reports.approve');
    Route::post('reports/{report}/send', [ReportController::class, 'send'])->name('api.reports.send');
    Route::post('reports/{report}/insights', [ReportInsightsController::class, 'store'])->name('api.reports.insights');
    Route::put('reports/{report}/narrative', [ReportNarrativeController::class, 'update'])->name('api.reports.narrative.update');
    Route::post('reports/{report}/narrative/regenerate', [ReportNarrativeController::class, 'regenerate'])->name('api.reports.narrative.regenerate');
    Route::put('reports/{report}/advisory', [ReportNarrativeController::class, 'updateAdvisory'])->name('api.reports.advisory.update');
    Route::post('reports/{report}/advisory/regenerate', [ReportNarrativeController::class, 'regenerateAdvisory'])->name('api.reports.advisory.regenerate');
    Route::get('reports/{report}/comments', [ReportCommentController::class, 'index'])->name('api.reports.comments.index');
    Route::post('reports/{report}/comments', [ReportCommentController::class, 'store'])->name('api.reports.comments.store');
    Route::delete('comments/{comment}', [ReportCommentController::class, 'destroy'])->name('api.comments.destroy');
    Route::get('reports/{report}/work-logs', [WorkLogController::class, 'index'])->name('api.reports.work-logs.index');
    Route::post('reports/{report}/work-logs', [WorkLogController::class, 'store'])->name('api.reports.work-logs.store');

    // Self-updater (CLAUDE.md §8/§12); privileged users only (checked in the controller).
    Route::get('system/update/status', [SystemUpdateController::class, 'status'])->name('api.system.update.status');
    Route::post('system/update/check', [SystemUpdateController::class, 'check'])->name('api.system.update.check');
    Route::post('system/update/run', [SystemUpdateController::class, 'run'])->name('api.system.update.run');
    Route::post('system/update/restart-workers', [SystemUpdateController::class, 'restartWorkers'])->name('api.system.update.restart-workers');
    Route::post('system/update/rollback', [SystemUpdateController::class, 'rollback'])->name('api.system.update.rollback');

    Route::get('system/site-agent/version', [SiteAgentController::class, 'version'])->name('api.system.site-agent.version');
    Route::get('system/site-agent/download', [SiteAgentController::class, 'download'])->name('api.system.site-agent.download');
});
