<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Reports\MetricBagLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricBagLoaderTest extends TestCase
{
    use RefreshDatabase;

    private function loader(): MetricBagLoader
    {
        return app(MetricBagLoader::class);
    }

    public function test_it_prefers_the_snapshot_synced_for_the_exact_range_over_a_monthly_one(): void
    {
        $agency = Agency::factory()->create();
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);

        // Monthly snapshots (each aggregated for its month at source).
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30 23:59:59',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => 100]],
        ]);
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31 23:59:59',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => 50]],
        ]);
        // A snapshot synced for the WHOLE year (the connector aggregated the year at source).
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31 23:59:59',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => 1200]],
        ]);

        $bags = $this->loader()->forSite($source->site_id, new Period('2026-01-01', '2026-12-31'));

        // The exact-range (yearly) snapshot wins, not the overlapping December one.
        $this->assertSame(1200, $bags['ga4']['ga4.sessions']);
    }

    public function test_it_falls_back_to_the_latest_overlapping_snapshot_when_no_exact_match(): void
    {
        $agency = Agency::factory()->create();
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);

        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30 23:59:59',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => 42]],
        ]);

        // No snapshot matches the requested span exactly → use the overlapping one.
        $bags = $this->loader()->forSite($source->site_id, new Period('2026-06-15', '2026-07-15'));

        $this->assertSame(42, $bags['ga4']['ga4.sessions']);
    }
}
