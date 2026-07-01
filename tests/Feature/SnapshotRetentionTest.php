<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Services\SnapshotRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SnapshotRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-26 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function snapshot(DataSource $source, string $end): MetricSnapshot
    {
        return MetricSnapshot::factory()->create([
            'agency_id' => $source->agency_id,
            'data_source_id' => $source->id,
            'period_start' => Carbon::parse($end)->startOfMonth(),
            'period_end' => $end,
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['x' => 1]],
        ]);
    }

    public function test_it_prunes_snapshots_past_the_window_but_keeps_the_latest_per_source(): void
    {
        $agency = Agency::factory()->create(['snapshot_retention_months' => 12]);
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);

        $old1 = $this->snapshot($source, '2023-05-31 23:59:59');
        $old2 = $this->snapshot($source, '2024-01-31 23:59:59');
        $recent = $this->snapshot($source, '2026-06-30 23:59:59');

        // A second source whose ONLY snapshot is old — must be kept (never empty a source).
        $sourceB = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);
        $onlyOld = $this->snapshot($sourceB, '2023-01-31 23:59:59');

        $deleted = app(SnapshotRetentionService::class)->pruneAgency($agency);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseMissing('ir_metric_snapshots', ['id' => $old1->id]);
        $this->assertDatabaseMissing('ir_metric_snapshots', ['id' => $old2->id]);
        $this->assertDatabaseHas('ir_metric_snapshots', ['id' => $recent->id]);
        $this->assertDatabaseHas('ir_metric_snapshots', ['id' => $onlyOld->id]);
    }

    public function test_no_retention_keeps_everything(): void
    {
        $agency = Agency::factory()->create(['snapshot_retention_months' => null]);
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);
        $this->snapshot($source, '2020-01-31 23:59:59');

        $service = app(SnapshotRetentionService::class);

        $this->assertSame(0, $service->pruneAgency($agency));
        $this->assertSame(['snapshots' => 0, 'bytes' => 0], $service->preview($agency));
        $this->assertDatabaseCount('ir_metric_snapshots', 1);
    }

    public function test_preview_counts_what_would_be_pruned(): void
    {
        $agency = Agency::factory()->create(['snapshot_retention_months' => 6]);
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);

        $this->snapshot($source, '2024-01-31 23:59:59'); // prunable
        $this->snapshot($source, '2026-06-30 23:59:59'); // latest → kept

        $preview = app(SnapshotRetentionService::class)->preview($agency);

        $this->assertSame(1, $preview['snapshots']);
        $this->assertGreaterThan(0, $preview['bytes']);
    }

    public function test_retention_comes_from_the_plan_not_the_agency(): void
    {
        // Retention is a PLATFORM setting now: it derives from the plan (a per-agency
        // override wins), and the agency can no longer set it via its own settings.
        $service = app(SnapshotRetentionService::class);

        $plan = Plan::factory()->create(['retention_months' => 12]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id, 'snapshot_retention_months' => null]);
        $this->assertSame(12, $service->effectiveMonths($agency->fresh(['plan'])));

        $agency->update(['plan_overrides' => ['retention_months' => 3]]);
        $this->assertSame(3, $service->effectiveMonths($agency->fresh(['plan'])));
    }

    public function test_the_agency_endpoint_ignores_a_retention_field(): void
    {
        $agency = Agency::factory()->create(['snapshot_retention_months' => null]);
        $user = User::factory()->create(['agency_id' => $agency->id]);

        $this->actingAs($user)
            ->putJson('/api/v1/agency', ['name' => 'Imagina', 'snapshot_retention_months' => 18])
            ->assertOk();

        // The agency can't set retention anymore — the value stays untouched.
        $this->assertNull($agency->refresh()->snapshot_retention_months);
    }
}
