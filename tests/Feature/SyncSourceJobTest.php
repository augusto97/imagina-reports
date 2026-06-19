<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Enums\DataSourceType;
use App\Jobs\SyncSourceJob;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Services\SyncService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Connectors\FakeConnector;
use Tests\TestCase;

class SyncSourceJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_a_source_without_a_pre_bound_tenant(): void
    {
        app(ConnectorRegistry::class)->register(
            new FakeConnector(DataSourceType::MainWp->value, 'MainWP', MetricSet::ok(['fake.visits' => 7])),
        );

        $agency = Agency::factory()->create();
        // Create the source under a temporary tenant, then clear it: the job must
        // resolve the agency itself, as it would on a queue worker.
        app(TenantContext::class)->actingAs($agency->id, function () use ($agency): void {
            DataSource::factory()->create([
                'agency_id' => $agency->id,
                'type' => DataSourceType::MainWp,
            ]);
        });
        app(TenantContext::class)->forget();

        $source = DataSource::query()->withoutGlobalScopes()->firstOrFail();

        (new SyncSourceJob($source->id, '2026-06-01', '2026-06-30'))
            ->handle(app(SyncService::class), app(TenantContext::class));

        $snapshot = MetricSnapshot::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($agency->id, $snapshot->agency_id);
        $this->assertSame(['fake.visits' => 7], $snapshot->payload['metrics']);

        // The job restores the empty tenant context afterwards.
        $this->assertFalse(app(TenantContext::class)->hasAgency());
    }

    public function test_it_is_a_no_op_when_the_source_is_gone(): void
    {
        (new SyncSourceJob(999, '2026-06-01', '2026-06-30'))
            ->handle(app(SyncService::class), app(TenantContext::class));

        $this->assertSame(0, MetricSnapshot::query()->withoutGlobalScopes()->count());
    }
}
