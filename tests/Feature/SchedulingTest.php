<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Enums\DataSourceType;
use App\Jobs\DeliverReportJob;
use App\Jobs\RunScheduledReportJob;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Schedule;
use App\Models\Site;
use App\Services\ScheduleRunner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Connectors\FakeConnector;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_due_schedules_and_advances_next_run(): void
    {
        Queue::fake();

        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);
        $schedule = Schedule::factory()->due()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
        ]);

        $count = app(ScheduleRunner::class)->dispatchDue();

        $this->assertSame(1, $count);
        Queue::assertPushed(RunScheduledReportJob::class);
        $this->assertTrue($schedule->fresh()?->next_run_at->isFuture());
    }

    public function test_it_ignores_schedules_that_are_not_due(): void
    {
        Queue::fake();

        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);
        Schedule::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'next_run_at' => now()->addMonth(),
        ]);

        $this->assertSame(0, app(ScheduleRunner::class)->dispatchDue());
        Queue::assertNothingPushed();
    }

    public function test_the_command_runs(): void
    {
        $this->artisan('reports:run-schedules')->assertSuccessful();
    }

    public function test_a_suspended_agency_gets_no_scheduled_reports(): void
    {
        // The scheduler bypasses the web middleware, so the job itself must refuse to
        // generate (and email) reports for a suspended, non-paying agency.
        Queue::fake();

        $agency = Agency::factory()->create(['status' => 'suspended']);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);
        $schedule = Schedule::factory()->due()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        app()->call([new RunScheduledReportJob($schedule->id), 'handle']);

        $this->assertSame(0, Report::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_it_syncs_the_sites_sources_before_generating(): void
    {
        // FUN-1: the scheduled run must refresh data first, not build from stale/absent
        // snapshots. Registering a fake connector and running the job should leave a fresh
        // snapshot for the period AND a generated report.
        Queue::fake([DeliverReportJob::class]);
        app(ConnectorRegistry::class)->register(new FakeConnector(DataSourceType::MainWp->value, 'MainWP', MetricSet::ok(['fake.visits' => 42])));

        $agency = Agency::factory()->create(['status' => 'active']);
        app(TenantContext::class)->set($agency->id);
        $site = Site::factory()->create(['agency_id' => $agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::MainWp]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);
        $schedule = Schedule::factory()->due()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        app()->call([new RunScheduledReportJob($schedule->id), 'handle']);

        // A snapshot exists for the source → sync ran before generation.
        $this->assertSame(1, MetricSnapshot::query()->withoutGlobalScopes()->where('data_source_id', $source->id)->count());
        // And the report was generated and its delivery queued.
        $this->assertSame(1, Report::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_a_schedule_respects_the_monthly_report_limit(): void
    {
        Queue::fake();

        $plan = Plan::factory()->create(['max_reports_per_month' => 0]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id, 'status' => 'active']);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);
        $schedule = Schedule::factory()->due()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        app()->call([new RunScheduledReportJob($schedule->id), 'handle']);

        $this->assertSame(0, Report::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }
}
