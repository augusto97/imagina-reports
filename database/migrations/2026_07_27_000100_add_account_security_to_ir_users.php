<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-security columns (§11.1): TOTP two-factor and verified email changes.
 *
 * The 2FA secret and recovery codes are stored encrypted (see the User casts). A new login
 * email lands in `pending_email` until the owner of that address confirms it, so a mistyped
 * or hostile change can never lock someone out of their own account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->string('pending_email')->nullable()->after('email');
            $table->string('pending_email_token', 64)->nullable()->unique()->after('pending_email');
            $table->timestamp('pending_email_sent_at')->nullable()->after('pending_email_token');
        });
    }

    public function down(): void
    {
        Schema::table('ir_users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'pending_email',
                'pending_email_token',
                'pending_email_sent_at',
            ]);
        });
    }
};
