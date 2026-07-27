<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of sensitive actions (who changed what, from where). Answers "who made this
 * report public?" or "who deleted that teammate?" — questions a multi-tenant SaaS must be
 * able to answer. Agency-scoped like every domain table (CLAUDE.md §5).
 *
 * `actor_id` is nullable and set null on delete so removing a user never erases the trail
 * of what they did; the denormalized `actor_name`/`actor_email` keep it readable afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('ir_users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'created_at']);
            $table->index(['agency_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_audit_logs');
    }
};
