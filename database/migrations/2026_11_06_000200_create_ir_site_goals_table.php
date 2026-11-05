<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's own goals (Etapa 4): "5.000 visitas al mes", "99,9 % de disponibilidad". They
 * belong to the SITE, not the template, so a linked template shows each client their own
 * targets through one «Metas» block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_site_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('ir_sites')->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('source', 40);
            $table->string('metric', 120);
            $table->decimal('target', 16, 4);
            // at_least: reach it (visits, sales) · at_most: stay under it (load time, budget)
            $table->string('direction', 10)->default('at_least');
            $table->string('format', 16)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['site_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_site_goals');
    }
};
