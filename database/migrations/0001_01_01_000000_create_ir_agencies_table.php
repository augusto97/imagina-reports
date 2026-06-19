<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agencies are the tenant root (CLAUDE.md §5). Every other domain table
     * carries an agency_id and is filtered by the AgencyScope global scope.
     */
    public function up(): void
    {
        Schema::create('ir_agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 9)->nullable();
            $table->string('default_locale', 8)->default('es');
            $table->string('domain')->nullable()->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_agencies');
    }
};
