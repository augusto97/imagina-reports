<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drive recurring report generation from the single cron (CLAUDE.md §5).
Schedule::command('reports:run-schedules')->hourly();

// Register newly published releases so the in-app updater can offer them (§12).
Schedule::command('system:check-updates')->hourly();

// Prune snapshots past each agency's retention window (CLAUDE.md §5). Daily, off-peak.
Schedule::command('snapshots:prune')->dailyAt('03:30');
