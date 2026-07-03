<?php

declare(strict_types=1);

use App\Models\Report;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize the three fields the reports LIST needs out of the heavy resolved_blocks JSON
 * (50-500 KB/row) into their own light columns (PERF-3), so the index no longer decodes the
 * full snapshot per row. Kept in sync by the Report model's saving hook. Existing rows are
 * backfilled from their current resolved_blocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_reports', function (Blueprint $table): void {
            $table->json('hidden_metrics')->nullable()->after('executive_summary');
            $table->boolean('has_advisory')->default(false)->after('hidden_metrics');
            $table->text('advisory')->nullable()->after('has_advisory');
        });

        Report::query()->withoutGlobalScopes()->chunkById(200, function ($reports): void {
            foreach ($reports as $report) {
                $report->forceFill($report->deriveListSummary())->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ir_reports', function (Blueprint $table): void {
            $table->dropColumn(['hidden_metrics', 'has_advisory', 'advisory']);
        });
    }
};
