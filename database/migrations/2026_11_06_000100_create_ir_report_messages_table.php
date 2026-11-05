<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation on a report (Etapa 4 «el cliente participa»): questions the client sends
 * from the portal, the agency's replies, and what the client asked the AI about the report.
 * Distinct from `ir_report_comments`, which are the agency's own notes printed in the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_report_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('ir_reports')->cascadeOnDelete();
            // client (a question/comment from the portal) · agency (a reply) · ai (asked the AI)
            $table->string('kind', 16);
            $table->foreignId('user_id')->nullable()->constrained('ir_users')->nullOnDelete();
            $table->string('author_name', 80)->nullable();
            $table->string('author_email', 190)->nullable();
            $table->text('body');
            // The AI's answer, for kind = ai.
            $table->text('answer')->nullable();
            // A client question the agency already answered or set aside.
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at']);
            $table->index(['agency_id', 'kind', 'handled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_report_messages');
    }
};
