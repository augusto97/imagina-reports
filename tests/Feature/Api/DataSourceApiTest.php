<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataSourceApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function site(): Site
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);

        return Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
    }

    public function test_store_configures_a_data_source_without_leaking_credentials(): void
    {
        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/data-sources", [
            'type' => 'mainwp',
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'super-secret'],
        ])
            ->assertCreated()
            ->assertJsonPath('type', 'mainwp')
            ->assertJsonMissingPath('credentials');

        $this->assertDatabaseHas('ir_data_sources', [
            'site_id' => $site->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    public function test_test_connection_runs_the_connector(): void
    {
        $site = $this->site();
        // MainWP scopes per-site (CLAUDE.md §5): the dashboard must manage this URL.
        Http::fake(['*' => Http::response(['data' => [['name' => $site->name, 'url' => $site->url]]])]);
        $source = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'x'],
        ]);

        $this->postJson("/api/v1/data-sources/{$source->id}/test")
            ->assertOk()
            ->assertJsonPath('successful', true);
    }

    public function test_update_changes_config_and_keeps_blank_secrets(): void
    {
        $site = $this->site();
        $source = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://old.test'],
            'credentials' => ['token' => 'original-token'],
            'status' => 'ok',
        ]);

        $this->putJson("/api/v1/data-sources/{$source->id}", [
            'config' => ['dashboard_url' => 'https://new.test'],
            'credentials' => ['token' => ''], // blank → keep existing
        ])
            ->assertOk()
            ->assertJsonPath('config.dashboard_url', 'https://new.test')
            ->assertJsonPath('status', 'pending') // reset for re-test
            ->assertJsonMissingPath('credentials');

        $source->refresh();
        $this->assertSame('original-token', $source->credentials['token']);
        $this->assertSame('https://new.test', $source->config['dashboard_url']);
    }

    public function test_update_replaces_a_provided_secret(): void
    {
        $site = $this->site();
        $source = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'old'],
        ]);

        $this->putJson("/api/v1/data-sources/{$source->id}", [
            'credentials' => ['token' => 'renewed'],
        ])->assertOk();

        $this->assertSame('renewed', $source->refresh()->credentials['token']);
    }

    public function test_destroy_removes_the_data_source(): void
    {
        $site = $this->site();
        $source = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp,
            'config' => [],
            'credentials' => [],
        ]);

        $this->deleteJson("/api/v1/data-sources/{$source->id}")->assertNoContent();
        $this->assertDatabaseMissing('ir_data_sources', ['id' => $source->id]);
    }

    public function test_index_mints_and_exposes_a_push_token_for_crowdsec(): void
    {
        $site = $this->site();
        $source = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::CrowdSec,
            'config' => [],
            'credentials' => [],
            'push_token' => null,
        ]);

        $this->getJson("/api/v1/sites/{$site->id}/data-sources")
            ->assertOk()
            ->assertJsonPath('0.is_push', true)
            ->assertJsonPath('0.ingest_url', fn (?string $url): bool => is_string($url) && str_contains($url, '/api/v1/ingest/'));

        // The token was generated lazily and persisted, so the install snippet is stable.
        $this->assertNotNull($source->refresh()->push_token);
    }

    public function test_a_data_source_of_another_agency_is_not_reachable(): void
    {
        $other = Agency::factory()->create();
        $otherClient = Client::factory()->create(['agency_id' => $other->id]);
        $otherSite = Site::factory()->create(['agency_id' => $other->id, 'client_id' => $otherClient->id]);
        $source = DataSource::factory()->create([
            'agency_id' => $other->id,
            'site_id' => $otherSite->id,
            'type' => DataSourceType::MainWp,
            'config' => [],
            'credentials' => [],
        ]);

        $this->putJson("/api/v1/data-sources/{$source->id}", ['config' => []])->assertNotFound();
        $this->deleteJson("/api/v1/data-sources/{$source->id}")->assertNotFound();
    }

    public function test_coverage_reports_the_stored_data_span_and_size_per_source(): void
    {
        $site = $this->site();
        $source = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);
        $empty = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Gsc]);

        MetricSnapshot::factory()->create([
            'agency_id' => $this->agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => 10]],
        ]);
        MetricSnapshot::factory()->create([
            'agency_id' => $this->agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => 20]],
        ]);

        $response = $this->getJson("/api/v1/sites/{$site->id}/data-sources/coverage")->assertOk();

        $covered = collect($response->json())->firstWhere('data_source_id', $source->id);
        $this->assertNotNull($covered);
        $this->assertStringStartsWith('2026-04-01', (string) $covered['period_start']);
        $this->assertStringStartsWith('2026-06-30', (string) $covered['period_end']);
        $this->assertSame(2, $covered['snapshots']);
        $this->assertGreaterThan(0, $covered['bytes']);

        // A source with no snapshots is still listed, with nulls/zeros.
        $blank = collect($response->json())->firstWhere('data_source_id', $empty->id);
        $this->assertNotNull($blank);
        $this->assertNull($blank['period_start']);
        $this->assertSame(0, $blank['snapshots']);
    }
}
