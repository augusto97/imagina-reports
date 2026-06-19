<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Connectors\MetricSetStatus;
use App\Enums\DataSourceType;
use App\Jobs\SyncSourceJob;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_a_draft_layout_against_real_snapshot_data(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::Ga4,
        ]);

        $start = now()->startOfMonth();
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
            'payload' => [
                'status' => MetricSetStatus::Ok->value,
                'error' => null,
                'metrics' => ['ga4.sessions' => 4321],
            ],
        ]);

        $blocks = [
            ['id' => 'h1', 'type' => 'header', 'binding' => null, 'props' => [], 'style' => []],
            ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => [], 'style' => []],
        ];

        $response = $this->postJson("/api/v1/sites/{$site->id}/preview", [
            'blocks' => $blocks,
        ])->assertOk();

        $response->assertJsonPath('has_data', true);
        $response->assertJsonPath('data.k1', 4321);
        $this->assertContains('ga4', $response->json('sources_with_data'));
    }

    public function test_it_matches_a_snapshot_when_period_is_sent_as_dates(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::Ga4,
        ]);

        // Snapshot spans the whole month, ending at 23:59:59 — as the sync stores it.
        $start = now()->startOfMonth();
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
            'payload' => [
                'status' => MetricSetStatus::Ok->value,
                'error' => null,
                'metrics' => ['ga4.sessions' => 99],
            ],
        ]);

        // Editor sends the month as plain dates: end parses to 00:00:00 — must still match.
        $response = $this->postJson("/api/v1/sites/{$site->id}/preview", [
            'blocks' => [
                ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => [], 'style' => []],
            ],
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
        ])->assertOk();

        $response->assertJsonPath('has_data', true);
        $response->assertJsonPath('data.k1', 99);
    }

    public function test_it_enriches_a_kpi_with_the_previous_period_comparison(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::Ga4,
        ]);

        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => $thisMonth,
            'period_end' => $thisMonth->copy()->endOfMonth(),
            'payload' => ['status' => MetricSetStatus::Ok->value, 'error' => null, 'metrics' => ['ga4.sessions' => 120]],
        ]);
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => $lastMonth,
            'period_end' => $lastMonth->copy()->endOfMonth(),
            'payload' => ['status' => MetricSetStatus::Ok->value, 'error' => null, 'metrics' => ['ga4.sessions' => 100]],
        ]);

        $response = $this->postJson("/api/v1/sites/{$site->id}/preview", [
            'blocks' => [
                [
                    'id' => 'k1',
                    'type' => 'kpi',
                    'binding' => ['source' => 'ga4', 'metric' => 'sessions', 'compare' => 'prev_period'],
                    'props' => [],
                    'style' => [],
                ],
            ],
        ])->assertOk();

        $response->assertJsonPath('data.k1.value', 120);
        $response->assertJsonPath('data.k1.previous', 100);
        $response->assertJsonPath('data.k1.change_percent', 20);
    }

    public function test_it_hides_blocks_whose_metric_has_no_data(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        $blocks = [
            ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => [], 'style' => []],
        ];

        $response = $this->postJson("/api/v1/sites/{$site->id}/preview", [
            'blocks' => $blocks,
        ])->assertOk();

        $response->assertJsonPath('has_data', false);
        $this->assertSame([], $response->json('blocks'));
    }

    public function test_it_rejects_a_malformed_layout(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        $this->postJson("/api/v1/sites/{$site->id}/preview", [
            'blocks' => [['type' => 'kpi']], // missing id + binding
        ])->assertStatus(422)->assertJsonValidationErrors('blocks');
    }

    public function test_sync_now_queues_a_sync_for_each_source(): void
    {
        Queue::fake();

        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        DataSource::factory()->count(2)->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::Ga4,
        ]);

        $this->postJson("/api/v1/sites/{$site->id}/sync")
            ->assertStatus(202)
            ->assertJsonPath('queued', 2);

        Queue::assertPushed(SyncSourceJob::class, 2);
    }
}
