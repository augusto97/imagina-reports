<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data retention is a PLATFORM concern (SaaS): the plan defines how many months of
 * snapshots an agency keeps (null = forever). The agency no longer controls it. A
 * per-agency override still works via `plan_overrides['retention_months']`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_plans', function (Blueprint $table): void {
            $table->unsignedInteger('retention_months')->nullable()->after('max_reports_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('ir_plans', function (Blueprint $table): void {
            $table->dropColumn('retention_months');
        });
    }
};
