<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring report generation (CLAUDE.md §5). The scheduler enqueues work for
     * rows whose next_run_at is due, then advances next_run_at.
     */
    public function up(): void
    {
        Schema::create('ir_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained('ir_report_definitions')->cascadeOnDelete();
            $table->string('cadence');
            $table->timestamp('next_run_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_schedules');
    }
};
