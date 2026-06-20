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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SnapshotPeriodsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_distinct_periods_with_data_newest_first(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $ds = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);
        MetricSnapshot::factory()->create(['agency_id' => $agency->id, 'data_source_id' => $ds->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31 23:59:59', 'payload' => ['metrics' => []], 'captured_at' => now()]);
        MetricSnapshot::factory()->create(['agency_id' => $agency->id, 'data_source_id' => $ds->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30 23:59:59', 'payload' => ['metrics' => []], 'captured_at' => now()]);

        $res = $this->getJson("/api/v1/sites/{$site->id}/snapshot-periods")->assertOk()->json();
        $this->assertCount(2, $res);
        $this->assertStringStartsWith('2026-06', $res[0]['period_start']); // newest first
    }
}
