<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteAgentDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_the_plugin_zip_to_an_authenticated_user(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $response = $this->get('/api/v1/system/site-agent/download');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('imagina-reports-agent.zip', (string) $response->headers->get('content-disposition'));
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/system/site-agent/download')->assertUnauthorized();
    }

    public function test_it_reports_the_bundled_plugin_version(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson('/api/v1/system/site-agent/version')
            ->assertOk()
            ->assertJsonPath('version', fn (?string $value): bool => is_string($value) && preg_match('/^\d+\.\d+/', $value) === 1);
    }
}
