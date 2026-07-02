<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunScheduledReportJob;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Schedule;
use App\Services\ScheduleRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
