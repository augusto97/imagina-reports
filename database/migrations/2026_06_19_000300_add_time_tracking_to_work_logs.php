<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time tracking for the "what we did" work log (CLAUDE.md §11.5). The agency runs an
 * hourly support service, so each task can carry the minutes invested (OPTIONAL — some
 * tasks just describe what was done) and a category for the per-type breakdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_work_logs', function (Blueprint $table): void {
            $table->unsignedInteger('minutes')->nullable()->after('description');
            $table->string('category')->nullable()->after('minutes');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_work_logs', function (Blueprint $table): void {
            $table->dropColumn(['minutes', 'category']);
        });
    }
};
