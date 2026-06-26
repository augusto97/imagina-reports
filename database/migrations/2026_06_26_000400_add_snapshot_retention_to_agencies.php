<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data retention (CLAUDE.md §5): an agency can cap how long normalized snapshots are kept,
 * so stored data doesn't grow unbounded. Null = keep forever (the default — never surprise
 * an existing tenant by deleting data). Frozen reports keep their own resolved copy, so
 * pruning old snapshots never alters an already-generated report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('snapshot_retention_months')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->dropColumn('snapshot_retention_months');
        });
    }
};
