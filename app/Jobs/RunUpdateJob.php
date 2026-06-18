<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Update\UpdateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the self-update out of the request lifecycle (CLAUDE.md §12.3). The deployer
 * dispatches queue:restart only as its final step.
 */
final class RunUpdateJob implements ShouldQueue
{
    use Queueable;

    public function handle(UpdateManager $manager): void
    {
        $manager->update();
    }
}
