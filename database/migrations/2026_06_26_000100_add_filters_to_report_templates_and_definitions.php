<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page/dashboard-level dataset filters (CLAUDE.md §10 dashboards): a map keyed by
 * 'all' (whole dashboard) or page index → list of {dimension, op, value} rules the
 * agency bakes in at design time. Blocks' own filters override these (the cascade is
 * applied in BlockResolver/DatasetEngine). Nullable — no filters by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->json('filters')->nullable()->after('theme');
        });
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->json('filters')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->dropColumn('filters');
        });
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->dropColumn('filters');
        });
    }
};
