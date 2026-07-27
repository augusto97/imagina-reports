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

// Re-read live subscriptions from the providers so a lost webhook can't leave an agency
// active without paying (or paying without access). Runs before the cut-off below so it
// acts on freshly reconciled state (SaaS Fase 2).
Schedule::command('billing:reconcile')->dailyAt('03:50');

// Cut access once a grace window elapsed: overdue past the grace days, or a cancelled
// subscription whose already-paid period has ended (SaaS Fase 2).
Schedule::command('billing:enforce-overdue')->dailyAt('04:00');
