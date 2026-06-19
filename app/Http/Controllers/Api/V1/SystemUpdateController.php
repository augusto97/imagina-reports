<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunUpdateJob;
use App\Models\User;
use App\Services\Update\UpdateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * In-app self-update controls (CLAUDE.md §8/§12). Restricted to privileged users
 * (owner/admin). The actual update runs as a queued RunUpdateJob.
 */
final class SystemUpdateController extends Controller
{
    public function status(UpdateManager $manager): JsonResponse
    {
        return response()->json($manager->status());
    }

    /**
     * Poll GitHub on demand (the "Buscar actualizaciones" button) so the operator
     * need not wait for the hourly `system:check-updates` schedule, then return the
     * freshly-computed status. Harmless/read-only — any authenticated user may run it.
     */
    public function check(UpdateManager $manager): JsonResponse
    {
        Artisan::call('system:check-updates');

        return response()->json($manager->status());
    }

    public function run(Request $request, UpdateManager $manager): JsonResponse
    {
        $this->authorizePrivileged($request);

        $manager->markQueued();
        RunUpdateJob::dispatch();

        return response()->json(['message' => 'Update queued.'], 202);
    }

    public function rollback(Request $request, UpdateManager $manager): JsonResponse
    {
        $this->authorizePrivileged($request);

        $manager->rollback();

        return response()->json(['message' => 'Rollback executed.']);
    }

    private function authorizePrivileged(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role->isPrivileged(), 403);
    }
}
