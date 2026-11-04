<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connection links the agency sends to a client so they can connect their OWN Google/Meta/
 * WooCommerce accounts to one site without an account in the app. The token is looked up
 * by its hash; the encrypted copy only lets the agency copy the link again from the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_connect_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('ir_sites')->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->json('types');
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('ir_users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_connect_invites');
    }
};
