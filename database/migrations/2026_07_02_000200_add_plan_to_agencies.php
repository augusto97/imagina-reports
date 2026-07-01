<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attach a plan + status to each agency (SaaS Fase 1). `plan_overrides` lets the
 * platform admin tweak a single agency's limits without creating a bespoke plan.
 * `status` gates access (active / suspended).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('id')->constrained('ir_plans')->nullOnDelete();
            $table->string('status')->default('active')->after('domain');
            $table->json('plan_overrides')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('ir_agencies', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'status', 'plan_overrides']);
        });
    }
};
