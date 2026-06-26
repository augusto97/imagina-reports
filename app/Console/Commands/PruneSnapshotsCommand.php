<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SnapshotRetentionService;
use Illuminate\Console\Command;

/**
 * Prune normalized snapshots past each agency's retention window (CLAUDE.md §5). Scheduled
 * daily. Agencies without a retention setting keep everything; the latest snapshot per
 * source is always kept (see SnapshotRetentionService).
 */
final class PruneSnapshotsCommand extends Command
{
    protected $signature = 'snapshots:prune';

    protected $description = 'Delete metric snapshots older than each agency\'s retention window.';

    public function handle(SnapshotRetentionService $retention): int
    {
        $deleted = $retention->pruneAll();

        $this->info("Pruned {$deleted} snapshot(s) past retention.");

        return self::SUCCESS;
    }
}
