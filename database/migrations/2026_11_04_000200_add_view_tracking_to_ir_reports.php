<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the client actually opened the report: first/last visit to the portal and how many
 * visits (a visit = opens more than 30 minutes apart). The agency's own previews and the PDF
 * renderer don't count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_reports', function (Blueprint $table): void {
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ir_reports', function (Blueprint $table): void {
            $table->dropColumn(['first_viewed_at', 'last_viewed_at', 'view_count']);
        });
    }
};
