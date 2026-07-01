<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detected anomalies (CLAUDE.md §13) persisted so they show as an in-app alerts feed —
 * not only fired as `anomaly.detected` webhooks. One row per (report, type, metric),
 * refreshed on regeneration. Acknowledged alerts stay for history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_anomalies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('ir_sites')->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('ir_reports')->nullOnDelete();
            $table->string('type');
            $table->string('metric');
            $table->double('current_value');
            $table->double('previous_value');
            $table->double('change_percent');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_anomalies');
    }
};
