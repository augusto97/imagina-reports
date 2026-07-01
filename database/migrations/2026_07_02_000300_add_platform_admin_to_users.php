<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform owner layer (SaaS Fase 1): a super-admin that manages ALL agencies.
 * A platform admin has no home agency (agency_id null) and `is_platform_admin` = true;
 * they operate the platform panel and can impersonate an agency for support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('role');
            // Which agency the platform admin is currently "inside" (impersonating), if any.
            $table->unsignedBigInteger('impersonating_agency_id')->nullable()->after('is_platform_admin');
        });

        // A platform admin belongs to no agency, so the FK must allow null.
        Schema::table('ir_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('agency_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ir_users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
