<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The manual "what we did this month" work log (CLAUDE.md §5/§11.5) — dated
     * actions (optionally with a screenshot) that justify the support plan.
     */
    public function up(): void
    {
        Schema::create('ir_report_work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('ir_reports')->nullOnDelete();
            $table->foreignId('site_id')->constrained('ir_sites')->cascadeOnDelete();
            $table->timestamp('performed_at');
            $table->text('description');
            $table->string('screenshot_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_report_work_logs');
    }
};
