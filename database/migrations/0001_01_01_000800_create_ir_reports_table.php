<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A generated report (CLAUDE.md §5): a frozen snapshot of resolved blocks for a
     * period, plus the health score, summary, PDF path and a signed public token.
     */
    public function up(): void
    {
        Schema::create('ir_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained('ir_report_definitions')->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->json('resolved_blocks');
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->text('executive_summary')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('public_token', 64)->unique();
            $table->string('status')->default(ReportStatus::Draft->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_reports');
    }
};
