<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agency's audit trail (who did what, when, from where). Read-only and privileged:
 * it exists to hold people accountable, so a collaborator can neither read nor alter it.
 * Paginated — the trail only grows.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    private const PER_PAGE_MAX = 200;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePrivileged($request);

        $perPage = (int) $request->query('per_page', (string) self::PER_PAGE);
        $perPage = max(1, min($perPage, self::PER_PAGE_MAX));

        $action = $request->query('action');

        $logs = AuditLog::query()
            ->when(is_string($action) && $action !== '', fn ($query) => $query->where('action', $action))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => array_map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'summary' => $log->summary,
                'actor_name' => $log->actor_name,
                'actor_email' => $log->actor_email,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ], $logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
