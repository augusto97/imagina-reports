<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task status for the work log (CLAUDE.md §11.5). The agency can now jot ongoing or
 * planned tasks — not just finished work — so the log doubles as a lightweight task
 * board. Only `done` tasks feed the client-facing report; `in_progress`/`planned`
 * stay internal. Existing rows are backfilled to `done` (they were completed work).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_work_logs', function (Blueprint $table): void {
            $table->string('status')->default('done')->after('description');
        });

        DB::table('ir_report_work_logs')->update(['status' => 'done']);
    }

    public function down(): void
    {
        Schema::table('ir_report_work_logs', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
