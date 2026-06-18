<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AiTemplateController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ConnectorController;
use App\Http\Controllers\Api\V1\DataSourceController;
use App\Http\Controllers\Api\V1\MetricCatalogController;
use App\Http\Controllers\Api\V1\PublicReportController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportDefinitionController;
use App\Http\Controllers\Api\V1\ReportTemplateController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SiteController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

// Authenticated, tenant-bound routes. `tenant` runs after `auth:sanctum` so the
// AgencyScope is active for everything inside (CLAUDE.md §5). Resource endpoints
// are added phase by phase per §8.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/user', static fn (Request $request) => $request->user())->name('api.user');

    Route::get('connectors', [ConnectorController::class, 'index'])->name('api.connectors.index');

    Route::get('clients', [ClientController::class, 'index'])->name('api.clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('api.clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('api.clients.show');

    Route::get('sites', [SiteController::class, 'index'])->name('api.sites.index');
    Route::post('sites', [SiteController::class, 'store'])->name('api.sites.store');
    Route::get('sites/{site}', [SiteController::class, 'show'])->name('api.sites.show');

    Route::get('sites/{site}/data-sources', [DataSourceController::class, 'index'])->name('api.sites.data-sources.index');
    Route::post('sites/{site}/data-sources', [DataSourceController::class, 'store'])->name('api.sites.data-sources.store');
    Route::get('sites/{site}/metric-catalog', [MetricCatalogController::class, 'show'])->name('api.sites.metric-catalog');
    Route::post('sites/{site}/ai-template', [AiTemplateController::class, 'store'])->name('api.sites.ai-template');
    Route::post('data-sources/{dataSource}/test', [DataSourceController::class, 'test'])->name('api.data-sources.test');

    Route::get('report-templates', [ReportTemplateController::class, 'index'])->name('api.report-templates.index');
    Route::post('report-templates', [ReportTemplateController::class, 'store'])->name('api.report-templates.store');
    Route::get('report-templates/{reportTemplate}', [ReportTemplateController::class, 'show'])->name('api.report-templates.show');
    Route::put('report-templates/{reportTemplate}', [ReportTemplateController::class, 'update'])->name('api.report-templates.update');

    Route::get('report-definitions', [ReportDefinitionController::class, 'index'])->name('api.report-definitions.index');
    Route::post('report-definitions', [ReportDefinitionController::class, 'store'])->name('api.report-definitions.store');
    Route::get('report-definitions/{reportDefinition}', [ReportDefinitionController::class, 'show'])->name('api.report-definitions.show');
    Route::put('report-definitions/{reportDefinition}', [ReportDefinitionController::class, 'update'])->name('api.report-definitions.update');

    Route::get('schedules', [ScheduleController::class, 'index'])->name('api.schedules.index');
    Route::post('schedules', [ScheduleController::class, 'store'])->name('api.schedules.store');

    Route::get('reports', [ReportController::class, 'index'])->name('api.reports.index');
    Route::post('reports/generate', [ReportController::class, 'generate'])->name('api.reports.generate');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('api.reports.show');
    Route::post('reports/{report}/approve', [ReportController::class, 'approve'])->name('api.reports.approve');
});
