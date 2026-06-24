<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-source push token (CLAUDE.md §9 — CrowdSec push model). Push-capable connectors
 * (e.g. CrowdSec running on each client VPS) can't be polled without exposing an inbound
 * port, so instead each VPS POSTs its already-aggregated data outbound to the ingest
 * endpoint, authenticated by this secret. Null for poll-based sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->string('push_token', 64)->nullable()->unique()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->dropColumn('push_token');
        });
    }
};
