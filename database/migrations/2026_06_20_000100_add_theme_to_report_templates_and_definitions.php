<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-report theme/branding (CLAUDE.md §11): an accent color + density applied to the
 * whole report, overriding the agency default. Nullable — reports inherit the agency
 * brand when unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->json('theme')->nullable()->after('blocks');
        });
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->json('theme')->nullable()->after('blocks');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};
