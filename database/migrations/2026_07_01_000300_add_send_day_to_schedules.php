<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day of the month a monthly schedule fires (CLAUDE.md §5) — so the agency can
 * designate WHEN the automatic report goes out (e.g. the 5th), not just the 1st.
 * Null keeps the default (day 1). Capped at 28 so every month has that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_schedules', function (Blueprint $table): void {
            $table->unsignedTinyInteger('send_day')->nullable()->after('cadence');
        });
    }

    public function down(): void
    {
        Schema::table('ir_schedules', function (Blueprint $table): void {
            $table->dropColumn('send_day');
        });
    }
};
