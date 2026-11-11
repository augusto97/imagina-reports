<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-panel notices for each team member (the bell): a source was connected, stopped working,
 * works again or was removed. One row per person, so each one reads and clears their own.
 * `data_source_id` has no foreign key on purpose: a «source removed» notice must outlive the
 * source it talks about. Each person's choices (which events, by email, which sites) live in
 * `ir_users.notification_prefs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('ir_users')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->foreignId('site_id')->nullable()->constrained('ir_sites')->nullOnDelete();
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('ir_users', function (Blueprint $table): void {
            $table->json('notification_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ir_users', function (Blueprint $table): void {
            $table->dropColumn('notification_prefs');
        });

        Schema::dropIfExists('ir_notifications');
    }
};
