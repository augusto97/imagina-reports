<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-reported metadata about a source (not a secret, not a metric): e.g. the
 * Site Agent plugin version + since when it has been logging. Surfaced on the source
 * card so the agency can spot a site running an outdated agent (which silently omits
 * newer metrics like the applied-updates history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('ir_data_sources', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
