<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PublicReportController;
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
});
