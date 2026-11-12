<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Reports\MetricBagLoader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which stored numbers a report reads: the period it asks for, and a previous period of the
 * same kind — never a quarter's numbers under a month's label, or a month compared with a year.
 */
class MetricBagLoaderTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $this->source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);
    }

    private function snapshot(string $start, string $end, int $visits): void
    {
        MetricSnapshot::factory()->create([
            'agency_id' => $this->source->agency_id,
            'data_source_id' => $this->source->id,
            'period_start' => $start,
            'period_end' => $end,
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['ga4.sessions' => $visits]],
        ]);
    }

    public function test_the_previous_month_is_the_calendar_month_before(): void
    {
        $this->snapshot('2026-04-01', '2026-04-30', 900);
        $this->snapshot('2026-05-01', '2026-05-31', 1000);
        // A longer range that ended later must not be taken as «the previous period».
        $this->snapshot('2026-03-01', '2026-05-31', 3000);

        $previous = app(MetricBagLoader::class)->previousForSite((int) $this->source->site_id, Period::make('2026-06-01', '2026-06-30'));

        $this->assertSame(1000, $previous['ga4']['ga4.sessions']);
    }

    public function test_a_period_of_another_length_is_never_the_comparison(): void
    {
        $this->snapshot('2026-01-01', '2026-05-31', 5000);

        $previous = app(MetricBagLoader::class)->previousForSite((int) $this->source->site_id, Period::make('2026-06-01', '2026-06-30'));

        $this->assertSame([], $previous);
    }

    public function test_a_month_never_reads_a_quarters_numbers(): void
    {
        $this->snapshot('2026-04-01', '2026-06-30', 3000);

        $bags = app(MetricBagLoader::class)->forSite((int) $this->source->site_id, Period::make('2026-06-01', '2026-06-30'));

        $this->assertSame([], $bags);
    }

    public function test_the_previous_month_handles_months_of_different_lengths(): void
    {
        $previous = MetricBagLoader::previousPeriod(Period::make('2026-03-01', '2026-03-31'));

        $this->assertSame('2026-02-01', $previous->start->toDateString());
        $this->assertSame('2026-02-28', $previous->end->toDateString());
    }
}
