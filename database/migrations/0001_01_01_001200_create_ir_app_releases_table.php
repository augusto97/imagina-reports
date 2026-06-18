<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Available application releases for the self-updater (CLAUDE.md §5/§12).
     * System-level (not agency-scoped).
     */
    public function up(): void
    {
        Schema::create('ir_app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->string('channel')->default('stable');
            $table->string('bundle_url');
            $table->string('checksum')->nullable();
            $table->timestamp('released_at');
            $table->timestamps();

            $table->index(['channel', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_app_releases');
    }
};
