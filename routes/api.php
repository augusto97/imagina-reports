<?php

declare(strict_types=1);

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

Route::get('/user', static fn (Request $request) => $request->user())
    ->middleware('auth:sanctum')
    ->name('api.user');
