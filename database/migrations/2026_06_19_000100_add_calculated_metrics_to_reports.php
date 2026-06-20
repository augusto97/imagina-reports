<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calculated metrics (CLAUDE.md §10.1 "free metrics"): per-template/definition
 * formulas over the metric bag (e.g. conversion rate, average order value). Stored
 * as JSON; computed at GENERATE/preview time into a `calc.*` bag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->json('calculated_metrics')->nullable()->after('blocks');
        });

        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->json('calculated_metrics')->nullable()->after('requested_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->dropColumn('calculated_metrics');
        });

        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->dropColumn('calculated_metrics');
        });
    }
};
