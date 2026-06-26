<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named report pages (CLAUDE.md §11 — Looker/Power-BI parity): an ordered list of page
 * metadata `[{name}]` indexed by the blocks' `page` index. Drives the interactive page
 * navigation menu (the viewer sees one page at a time and switches between them) and the
 * editor's page tabs. Nullable — pages fall back to "Página N" when unnamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->json('pages')->nullable()->after('filters');
        });
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->json('pages')->nullable()->after('filters');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_templates', function (Blueprint $table): void {
            $table->dropColumn('pages');
        });
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->dropColumn('pages');
        });
    }
};
