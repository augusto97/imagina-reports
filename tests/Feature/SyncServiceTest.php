<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Connectors\MetricSetStatus;
use App\Connectors\Period;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Services\SyncService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Connectors\FakeConnector;
use Tests\TestCase;

class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function registerConnector(?MetricSet $result = null): void
    {
        app(ConnectorRegistry::class)->register(
            new FakeConnector(DataSourceType::MainWp->value, 'MainWP', $result),
        );
    }

    private function dataSource(): DataSource
    {
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);

        return DataSource::factory()->create([
            'agency_id' => $agency->id,
            'type' => DataSourceType::MainWp,
            'status' => DataSourceStatus::Pending,
        ]);
    }

    public function test_it_persists_a_normalized_snapshot_and_marks_the_source_ok(): void
    {
        $this->registerConnector(MetricSet::ok(['fake.visits' => 100]));
        $source = $this->dataSource();
        $period = Period::make('2026-06-01', '2026-06-30');

        $snapshot = app(SyncService::class)->sync($source, $period);

        $this->assertSame(MetricSetStatus::Ok, $snapshot->status);
        $this->assertSame($source->agency_id, $snapshot->agency_id);
        $this->assertSame(['fake.visits' => 100], $snapshot->payload['metrics']);

        $source->refresh();
        $this->assertSame(DataSourceStatus::Ok, $source->status);
        $this->assertNotNull($source->last_synced_at);
        $this->assertNull($source->last_error);
    }

    public function test_resyncing_the_same_period_is_idempotent(): void
    {
        $this->registerConnector(MetricSet::ok(['fake.visits' => 1]));
        $source = $this->dataSource();
        $period = Period::make('2026-06-01', '2026-06-30');

        $first = app(SyncService::class)->sync($source, $period);
        $second = app(SyncService::class)->sync($source, $period);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MetricSnapshot::query()->count());
    }

    public function test_a_failed_fetch_marks_the_source_in_error(): void
    {
        $this->registerConnector(MetricSet::failed('auth rejected'));
        $source = $this->dataSource();
        $period = Period::make('2026-06-01', '2026-06-30');

        $snapshot = app(SyncService::class)->sync($source, $period);

        $this->assertSame(MetricSetStatus::Failed, $snapshot->status);

        $source->refresh();
        $this->assertSame(DataSourceStatus::Error, $source->status);
        $this->assertSame('auth rejected', $source->last_error);
    }
}
