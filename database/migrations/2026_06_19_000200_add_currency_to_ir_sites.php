<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site currency (CLAUDE.md §5). Each site reports in its own currency — Imagina
 * shows it as-is (no FX conversion): Colombian/Chilean peso, Peruvian sol, bolívar,
 * dollar, etc. Drives how `currency`-formatted amounts render in the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_sites', function (Blueprint $table): void {
            $table->string('currency', 3)->default('USD')->after('support_plan');
        });
    }

    public function down(): void
    {
        Schema::table('ir_sites', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
