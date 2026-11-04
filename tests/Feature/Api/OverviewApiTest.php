<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DataSourceStatus;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function site(Agency $agency): Site
    {
        $client = Client::factory()->create(['agency_id' => $agency->id]);

        return Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/overview')->assertUnauthorized();
    }

    public function test_it_lists_what_needs_attention_for_the_agency_only(): void
    {
        $agency = Agency::factory()->create();
        $site = $this->site($agency);
        $failing = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => 'ga4']);
        $failing->forceFill(['status' => DataSourceStatus::Error, 'last_error' => 'Token caducado'])->save();
        ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);

        // Another agency's broken source must never show up.
        $other = Agency::factory()->create();
        $otherSource = DataSource::factory()->create(['agency_id' => $other->id, 'site_id' => $this->site($other)->id, 'type' => 'gsc']);
        $otherSource->forceFill(['status' => DataSourceStatus::Error])->save();

        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->getJson('/api/v1/overview')
            ->assertOk()
            ->assertJsonPath('counts.sites', 1)
            ->assertJsonPath('counts.sources_failing', 1)
            ->assertJsonPath('counts.missing_reports', 1)
            ->assertJsonCount(1, 'failing_sources')
            ->assertJsonPath('failing_sources.0.error', 'Token caducado')
            ->assertJsonPath('failing_sources.0.label', 'Google Analytics 4')
            ->assertJsonPath("site_health.{$site->id}.errors", 1);
    }
}
