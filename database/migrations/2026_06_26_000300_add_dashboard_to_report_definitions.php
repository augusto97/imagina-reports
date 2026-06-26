<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live dashboard mode (CLAUDE.md §11.2/Etapa D): a definition can be published as a
 * permanent, always-current dashboard the client opens by its own token and explores
 * by date range. It re-resolves live from the latest snapshots (still §3.1 — only
 * stored snapshots, never a live API). Off by default; sharing (visibility/password)
 * reuses the columns added in the previous migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->boolean('dashboard_enabled')->default(false)->after('embed_domains');
            $table->string('dashboard_token', 64)->nullable()->unique()->after('dashboard_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->dropColumn(['dashboard_enabled', 'dashboard_token']);
        });
    }
};
