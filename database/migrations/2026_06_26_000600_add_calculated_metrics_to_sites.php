<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-level calculated metrics (CLAUDE.md §10.1): per-site formulas for clients that
 * need their own derived metric, layered ON TOP of the agency's reusable ones. Precedence
 * at generate time: report-level > site-level > agency-level (more specific wins).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_sites', function (Blueprint $table): void {
            $table->json('calculated_metrics')->nullable()->after('plan_hours');
        });
    }

    public function down(): void
    {
        Schema::table('ir_sites', function (Blueprint $table): void {
            $table->dropColumn('calculated_metrics');
        });
    }
};
