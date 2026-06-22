<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AiTemplateController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ConnectorController;
use App\Http\Controllers\Api\V1\DataSourceController;
use App\Http\Controllers\Api\V1\MetricCatalogController;
use App\Http\Controllers\Api\V1\PreviewController;
use App\Http\Controllers\Api\V1\PublicReportController;
use App\Http\Controllers\Api\V1\ReportCommentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportDefinitionController;
use App\Http\Controllers\Api\V1\ReportInsightsController;
use App\Http\Controllers\Api\V1\ReportNarrativeController;
use App\Http\Controllers\Api\V1\ReportTemplateController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SiteWorkLogController;
use App\Http\Controllers\Api\V1\SystemUpdateController;
use App\Http\Controllers\Api\V1\TrendsController;
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

    Route::get('clients', [ClientController::class, 'index'])->name('api.clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('api.clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('api.clients.show');

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
    Route::post('sites/{site}/data-sources', [DataSourceController::class, 'store'])->name('api.sites.data-sources.store');
    Route::get('sites/{site}/metric-catalog', [MetricCatalogController::class, 'show'])->name('api.sites.metric-catalog');
    Route::post('sites/{site}/ai-template', [AiTemplateController::class, 'store'])->name('api.sites.ai-template');
    Route::post('sites/{site}/preview', [PreviewController::class, 'preview'])->name('api.sites.preview');
    Route::post('sites/{site}/sync', [PreviewController::class, 'sync'])->name('api.sites.sync');
    Route::post('data-sources/{dataSource}/test', [DataSourceController::class, 'test'])->name('api.data-sources.test');

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

    Route::get('schedules', [ScheduleController::class, 'index'])->name('api.schedules.index');
    Route::post('schedules', [ScheduleController::class, 'store'])->name('api.schedules.store');

    // Agency-wide trends + multi-client comparisons (CLAUDE.md §13).
    Route::get('trends', [TrendsController::class, 'index'])->name('api.trends.index');

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
});
