<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's website (CLAUDE.md §5). Data sources attach to a site; report
     * definitions target a site. (ir_data_sources.site_id references this but keeps
     * no DB-level FK — added in Task 3 as a plain column for sqlite portability.)
     */
    public function up(): void
    {
        Schema::create('ir_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('ir_clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('hosting')->nullable();
            $table->string('support_plan')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_sites');
    }
};
