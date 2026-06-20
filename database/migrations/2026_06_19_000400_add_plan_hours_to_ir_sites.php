<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracted support hours per month for a site (CLAUDE.md §11.5). Drives the
 * "hours used vs plan" view so the client sees whether the hourly service paid off.
 * Nullable — not every plan is hour-capped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_sites', function (Blueprint $table): void {
            $table->decimal('plan_hours', 6, 2)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('ir_sites', function (Blueprint $table): void {
            $table->dropColumn('plan_hours');
        });
    }
};
