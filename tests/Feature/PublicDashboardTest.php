<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\ReportDefinition;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ReportDefinition, 1: Site}
     */
    private function publishedDashboard(array $overrides = []): array
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);

        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['fake.visits' => 100]],
        ]);

        $definition = ReportDefinition::factory()->create(array_merge([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'dashboard_enabled' => true,
            'dashboard_token' => 'dash-token-123',
        ], $overrides));

        return [$definition, $site];
    }

    public function test_it_serves_a_published_dashboard_for_a_date_range(): void
    {
        [$definition] = $this->publishedDashboard();

        $this->getJson("/api/v1/public/dashboards/{$definition->dashboard_token}?from=2026-05-01&to=2026-05-31")
            ->assertOk()
            ->assertJsonPath('status', 'live')
            ->assertJsonStructure(['blocks', 'data', 'range', 'period_start', 'period_end', 'agency']);
    }

    public function test_it_defaults_to_the_available_snapshot_range(): void
    {
        [$definition] = $this->publishedDashboard();

        // No from/to → opens on the latest snapshot's window.
        $this->getJson("/api/v1/public/dashboards/{$definition->dashboard_token}")
            ->assertOk()
            ->assertJsonPath('period_start', fn (string $value): bool => str_starts_with($value, '2026-05-01'))
            ->assertJsonPath('range.start', fn (string $value): bool => str_starts_with($value, '2026-05-01'));
    }

    public function test_a_disabled_dashboard_is_not_found(): void
    {
        [$definition] = $this->publishedDashboard(['dashboard_enabled' => false]);

        $this->getJson("/api/v1/public/dashboards/{$definition->dashboard_token}")->assertNotFound();
    }

    public function test_a_password_protected_dashboard_demands_the_password(): void
    {
        [$definition] = $this->publishedDashboard([
            'visibility' => 'password',
            'password_hash' => Hash::make('s3cret'),
        ]);

        $this->getJson("/api/v1/public/dashboards/{$definition->dashboard_token}")
            ->assertUnauthorized()
            ->assertJsonPath('requires_password', true);

        $this->getJson("/api/v1/public/dashboards/{$definition->dashboard_token}", ['X-Report-Password' => 's3cret'])
            ->assertOk();
    }

    public function test_a_private_dashboard_is_forbidden(): void
    {
        [$definition] = $this->publishedDashboard(['visibility' => 'private']);

        $this->getJson("/api/v1/public/dashboards/{$definition->dashboard_token}")->assertForbidden();
    }
}
