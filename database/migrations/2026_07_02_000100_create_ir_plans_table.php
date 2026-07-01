<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans for the multi-agency SaaS (roadmap: SaaS Fase 1). A plan holds
 * the LIMITS an agency is entitled to (null = unlimited) and feature flags. Price is
 * stored for later self-serve billing even though billing is manual for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);

            // Limits — null means unlimited.
            $table->unsignedInteger('max_sites')->nullable();
            $table->unsignedInteger('max_data_sources')->nullable();
            $table->unsignedInteger('max_clients')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_reports_per_month')->nullable();

            // null = every connector allowed; otherwise a whitelist of connector keys.
            $table->json('allowed_connectors')->nullable();
            // Feature flags: ai_builder, white_label, remove_branding, custom_domain.
            $table->json('features')->nullable();

            // For later billing (manual now).
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_plans');
    }
};
