<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The API is documented with OpenAPI, generated from the routes/FormRequests/Resources
 * by Scramble (CLAUDE.md §8) and served at /docs/api(.json). Access is restricted to
 * local env or the `viewApiDocs` gate; we open the gate here to assert generation works.
 */
class OpenApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_a_generated_openapi_document_for_the_v1_api(): void
    {
        $this->actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));
        Gate::define('viewApiDocs', static fn (User $user): bool => true);

        $data = $this->getJson('/docs/api.json')->assertOk()->json();

        $this->assertSame('3.1.0', $data['openapi'] ?? null);

        // Real v1 routes are present — proof it generated from the actual API surface.
        $paths = $data['paths'] ?? [];
        $this->assertArrayHasKey('/v1/clients', $paths);
        $this->assertArrayHasKey('/v1/reports/generate', $paths);
        $this->assertArrayHasKey('/v1/public/reports/{token}', $paths);
    }

    public function test_docs_are_restricted_outside_local(): void
    {
        // No gate defined + testing env (not local) → docs are not publicly exposed.
        $this->getJson('/docs/api.json')->assertForbidden();
    }
}
