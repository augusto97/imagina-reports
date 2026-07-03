<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the per-source push token at rest (audit SEC — a DB dump previously exposed every
 * site's ingest bearer token in plaintext, unlike `credentials`). The token becomes a
 * reversible-encrypted value (so the panel can still show it + the ingest URL), and a
 * deterministic SHA-256 `push_token_hash` becomes the ingest lookup key — the plaintext is
 * never queried, so a dump reveals no usable token. Existing plaintext tokens are dropped;
 * push-capable sources re-mint one the next time they're viewed (sites re-provision once).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the old plaintext column (and its unique index) entirely — the values can't be
        // read once the column is an encrypted cast, and we're invalidating them anyway.
        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->dropUnique(['push_token']);
        });

        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->dropColumn('push_token');
        });

        Schema::table('ir_data_sources', function (Blueprint $table): void {
            // TEXT: the ciphertext is far longer than the old 64-char plaintext.
            $table->text('push_token')->nullable()->after('config');
            // Deterministic hash of the token → the ingest lookup key.
            $table->string('push_token_hash', 64)->nullable()->unique()->after('push_token');
        });
    }

    public function down(): void
    {
        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->dropUnique(['push_token_hash']);
            $table->dropColumn(['push_token_hash', 'push_token']);
        });

        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->string('push_token', 64)->nullable()->unique()->after('config');
        });
    }
};
