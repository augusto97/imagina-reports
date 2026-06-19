<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable block-based report templates (CLAUDE.md §5/§10.2).
     */
    public function up(): void
    {
        Schema::create('ir_report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->string('name');
            $table->json('blocks');
            $table->boolean('is_default')->default(false);
            $table->string('locale', 8)->default('es');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_report_templates');
    }
};
