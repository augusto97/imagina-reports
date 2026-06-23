<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client IANA timezone (CLAUDE.md §5). Timestamped data shown to the client
 * (e.g. Better Stack incident times) is rendered in the client's own zone, since it
 * depends on the client's country. Null/empty falls back to UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_clients', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('ir_clients', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
