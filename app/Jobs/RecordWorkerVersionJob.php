<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Update\UpdateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records the version the QUEUE WORKER is running (CLAUDE.md §12). Dispatched from the
 * web layer; because it executes ON the worker, the version it writes is the worker's
 * real running code — so the System screen can flag a stale worker (web updated but the
 * worker still on old code because Horizon wasn't restarted).
 */
final class RecordWorkerVersionJob implements ShouldQueue
{
    use Queueable;

    public function handle(UpdateManager $manager): void
    {
        $manager->recordWorkerVersion();
    }
}
