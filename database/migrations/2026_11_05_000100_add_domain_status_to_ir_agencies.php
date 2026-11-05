<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domain for an agency's client-facing pages (reportes.suagencia.com). `domain`
 * already exists; this tracks where it is in the setup: waiting for the DNS record, DNS
 * fine but no HTTPS on our server yet, or live — plus the last check and why it failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->string('domain_status')->nullable()->after('domain');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_status');
            $table->string('domain_error', 500)->nullable()->after('domain_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->dropColumn(['domain_status', 'domain_checked_at', 'domain_error']);
        });
    }
};
