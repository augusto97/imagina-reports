<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RecordWorkerVersionJob;
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

        // Ask the worker to report its running version (surfaces a stale worker).
        RecordWorkerVersionJob::dispatch();

        return response()->json($manager->status());
    }

    /**
     * Restart the queue workers so they pick up freshly-deployed code. With Horizon a
     * plain queue:restart isn't enough — horizon:terminate makes Supervisor respawn the
     * master on the new release. Fixes "the web updated but generated reports still use
     * the old logic" without server access, and re-records the worker version after.
     */
    public function restartWorkers(Request $request, UpdateManager $manager): JsonResponse
    {
        $this->authorizePrivileged($request);

        // Best-effort: a failure in one restart path (e.g. Horizon not running) must not
        // 500 the request — the other path and the version re-check still run.
        foreach (['horizon:terminate', 'queue:restart'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        RecordWorkerVersionJob::dispatch();

        return response()->json(['message' => 'Workers restarting.'], 202);
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
