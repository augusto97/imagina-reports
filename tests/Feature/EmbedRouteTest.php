<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbedRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The embed page renders the SPA blade (@vite). Tests assert the HTTP response,
        // not the built assets, so stub Vite — the backend CI job doesn't build the SPAs.
        $this->withoutVite();
    }

    public function test_it_sets_frame_ancestors_to_the_allowlisted_domains(): void
    {
        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $agency->id,
            'embed_domains' => ['acme.com', 'panel.cliente.com'],
        ]);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        $this->get("/embed/{$report->public_token}")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors acme.com panel.cliente.com');
    }

    public function test_it_refuses_embedding_when_no_domains_are_allowlisted(): void
    {
        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'embed_domains' => null]);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        $this->get("/embed/{$report->public_token}")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/embed/nope')->assertNotFound();
    }
}
