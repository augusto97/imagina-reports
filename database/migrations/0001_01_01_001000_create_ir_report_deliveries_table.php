<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A delivery attempt of a report to a recipient (CLAUDE.md §5).
     */
    public function up(): void
    {
        Schema::create('ir_report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('ir_reports')->cascadeOnDelete();
            $table->string('channel');
            $table->string('recipient');
            $table->string('status');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_report_deliveries');
    }
};
