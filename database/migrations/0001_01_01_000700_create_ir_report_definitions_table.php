<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A site's report configuration (CLAUDE.md §5): a template (optional) plus
     * per-site block overrides, the metrics to sync, schedule and recipients.
     */
    public function up(): void
    {
        Schema::create('ir_report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('ir_sites')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('template_id')->nullable()->constrained('ir_report_templates')->nullOnDelete();
            $table->json('blocks')->nullable();
            $table->json('requested_metrics')->nullable();
            $table->string('locale', 8)->default('es');
            $table->json('schedule')->nullable();
            $table->json('recipients')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_report_definitions');
    }
};
