<?php

declare(strict_types=1);

use App\Enums\DataSourceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A configured connector instance (CLAUDE.md §5/§9). credentials are stored
     * via Laravel's encrypted cast and never logged. site_id is nullable with no
     * FK yet — the constraint to ir_sites is added when that table lands.
     */
    public function up(): void
    {
        Schema::create('ir_data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('ir_agencies')->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('type');
            $table->text('credentials')->nullable();
            $table->json('config')->nullable();
            $table->string('status')->default(DataSourceStatus::Pending->value);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_data_sources');
    }
};
