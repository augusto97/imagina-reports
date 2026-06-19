<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScheduleRunner;
use Illuminate\Console\Command;

/**
 * Dispatches generation for due report schedules (CLAUDE.md §5). Wired to the single
 * cron via routes/console.php.
 */
final class RunSchedulesCommand extends Command
{
    protected $signature = 'reports:run-schedules';

    protected $description = 'Enqueue generation for any due report schedules.';

    public function handle(ScheduleRunner $runner): int
    {
        $count = $runner->dispatchDue();

        $this->info("Dispatched {$count} scheduled report(s).");

        return self::SUCCESS;
    }
}
