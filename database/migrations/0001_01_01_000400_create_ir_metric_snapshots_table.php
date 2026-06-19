<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized, per-source × period snapshots (CLAUDE.md §3.1/§5). The SYNC stage
     * writes these; GENERATE reads them — external APIs are never touched at report
     * time. The unique key makes re-syncing a period idempotent (upsert, not dup).
     */
    public function up(): void
    {
        Schema::create('ir_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained('ir_data_sources')->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->json('payload');
            $table->string('status');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['data_source_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_metric_snapshots');
    }
};
