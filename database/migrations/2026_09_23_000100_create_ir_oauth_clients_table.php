<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant apps (Claude, ChatGPT, Cursor…) that registered themselves to connect to the MCP
 * endpoint (OAuth 2.0 Dynamic Client Registration, RFC 7591). Public clients: no secret, PKCE
 * mandatory. Not tenant-scoped — an app is the same for every agency; what an agency grants
 * it lives in the token issued to that agency's user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name', 120);
            $table->json('redirect_uris');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_oauth_clients');
    }
};
