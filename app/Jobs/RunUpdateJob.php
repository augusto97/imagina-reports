<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Update\UpdateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the self-update out of the request lifecycle (CLAUDE.md §12.3). The deployer
 * dispatches queue:restart only as its final step.
 *
 * A real deploy (download bundle → npm install puppeteer → migrate → config/route/view
 * cache → flip the symlink) takes minutes, so this job MUST override the worker's default
 * 60s timeout — otherwise the worker SIGKILLs it mid-deploy and the UI hangs forever on
 * "Instalando…". It also runs exactly once ($tries = 1): a half-applied deploy must never
 * be retried blindly (UpdateManager guards re-entry with a lock + per-version marker).
 */
final class RunUpdateJob implements ShouldQueue
{
    use Queueable;

    /** Give the deploy up to 30 minutes before the worker considers it timed out. */
    public int $timeout = 1800;

    /** Never auto-retry a deploy — a duplicate attempt would re-run migrations/flip. */
    public int $tries = 1;

    /** A timeout is a real failure: surface it (marks the run "failed") instead of retrying. */
    public bool $failOnTimeout = true;

    public function handle(UpdateManager $manager): void
    {
        $manager->update();
    }

    /**
     * The worker calls this if the job throws or times out. Record the failure so the
     * admin UI leaves "Instalando…" and shows an actionable error instead of hanging.
     */
    public function failed(?Throwable $exception): void
    {
        app(UpdateManager::class)->markFailed($exception?->getMessage());
    }
}
