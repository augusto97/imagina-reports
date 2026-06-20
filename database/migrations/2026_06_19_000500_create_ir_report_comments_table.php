<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report comments / annotations (CLAUDE.md §11). Two visibilities: `internal` notes
 * for the team while preparing the report, and `client` comments that render in the
 * report (portal + PDF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_report_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('ir_reports')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('ir_users')->nullOnDelete();
            $table->text('body');
            $table->string('visibility')->default('internal');
            $table->timestamps();

            $table->index(['report_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_report_comments');
    }
};
