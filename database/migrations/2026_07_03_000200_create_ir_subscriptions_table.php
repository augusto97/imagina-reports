<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agency's paid subscription (SaaS Fase 2 — billing). One active subscription per
 * agency, backed by a provider (mercadopago / paypal). `external_id` is the provider's
 * subscription/preapproval id; `status` drives whether the agency stays active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('ir_plans')->nullOnDelete();
            $table->string('provider');
            $table->string('external_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_subscriptions');
    }
};
