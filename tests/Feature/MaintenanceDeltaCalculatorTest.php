<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\Period;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Reports\MaintenanceDeltaCalculator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceDeltaCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_between_computes_updates_applied_and_net_deltas(): void
    {
        $earliest = MetricSnapshot::factory()->make([
            'agency_id' => 1,
            'data_source_id' => 1,
            'payload' => ['metrics' => ['mainwp.updates_available' => 10]],
            'captured_at' => CarbonImmutable::parse('2026-06-01'),
        ]);
        $latest = MetricSnapshot::factory()->make([
            'agency_id' => 1,
            'data_source_id' => 1,
            'payload' => ['metrics' => ['mainwp.updates_available' => 3]],
            'captured_at' => CarbonImmutable::parse('2026-06-28'),
        ]);

        $delta = (new MaintenanceDeltaCalculator)->between($earliest, $latest);

        $this->assertSame(7, $delta->updatesApplied);
        $this->assertSame(-7.0, $delta->deltas['mainwp.updates_available']);
    }

    public function test_applied_never_goes_negative_when_pending_updates_grow(): void
    {
        $earliest = MetricSnapshot::factory()->make([
            'payload' => ['metrics' => ['mainwp.updates_available' => 2]],
            'captured_at' => CarbonImmutable::parse('2026-06-01'),
        ]);
        $latest = MetricSnapshot::factory()->make([
            'payload' => ['metrics' => ['mainwp.updates_available' => 9]],
            'captured_at' => CarbonImmutable::parse('2026-06-28'),
        ]);

        $delta = (new MaintenanceDeltaCalculator)->between($earliest, $latest);

        $this->assertSame(0, $delta->updatesApplied);
    }

    public function test_for_data_source_diffs_the_period_boundary_snapshots(): void
    {
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $source = DataSource::factory()->create(['agency_id' => $agency->id]);

        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-01 00:00:00',
            'period_end' => '2026-06-01 23:59:59',
            'payload' => ['metrics' => ['mainwp.updates_available' => 12]],
            'captured_at' => '2026-06-01 06:00:00',
        ]);
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-28 00:00:00',
            'period_end' => '2026-06-28 23:59:59',
            'payload' => ['metrics' => ['mainwp.updates_available' => 4]],
            'captured_at' => '2026-06-28 06:00:00',
        ]);

        $delta = (new MaintenanceDeltaCalculator)->forDataSource($source->id, Period::make('2026-06-01', '2026-06-30'));

        $this->assertNotNull($delta);
        $this->assertSame(8, $delta->updatesApplied);
    }

    public function test_for_data_source_returns_null_without_two_snapshots(): void
    {
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $source = DataSource::factory()->create(['agency_id' => $agency->id]);

        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-01 00:00:00',
            'period_end' => '2026-06-01 23:59:59',
        ]);

        $delta = (new MaintenanceDeltaCalculator)->forDataSource($source->id, Period::make('2026-06-01', '2026-06-30'));

        $this->assertNull($delta);
    }
}
