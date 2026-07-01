<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settings (SaaS Fase 2 — billing). A single row holds the payment
 * provider credentials (encrypted in the model): MercadoPago access token, PayPal
 * client id/secret, and a sandbox toggle. Owned by the platform, never an agency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_platform_settings');
    }
};
