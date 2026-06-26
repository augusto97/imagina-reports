<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing & privacy for the shareable dashboard (CLAUDE.md §10/Etapa D): the definition
 * (not each period report) carries the visibility, the optional password hash, and the
 * allowed embed domains. Public by default — existing reports stay openable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->string('visibility')->default('public')->after('filters');
            $table->string('password_hash')->nullable()->after('visibility');
            $table->json('embed_domains')->nullable()->after('password_hash');
        });
    }

    public function down(): void
    {
        Schema::table('ir_report_definitions', function (Blueprint $table): void {
            $table->dropColumn(['visibility', 'password_hash', 'embed_domains']);
        });
    }
};
