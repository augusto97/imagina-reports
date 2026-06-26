<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-level reusable calculated metrics (CLAUDE.md §10.1): formulas over existing
 * metrics (e.g. revenue / orders = AOV) defined ONCE for the whole agency and available
 * in every report's binding picker — instead of re-typing them per template. They merge
 * with (and are overridden by) a report's own calculated_metrics at generate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->json('calculated_metrics')->nullable()->after('snapshot_retention_months');
        });
    }

    public function down(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->dropColumn('calculated_metrics');
        });
    }
};
