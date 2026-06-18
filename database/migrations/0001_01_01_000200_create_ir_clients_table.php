<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clients are the first agency-scoped domain entity (CLAUDE.md §5). It is the
     * canonical example for the AgencyScope tenant isolation enforced by §14.
     */
    public function up(): void
    {
        Schema::create('ir_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_email')->nullable();
            $table->string('locale', 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_clients');
    }
};
