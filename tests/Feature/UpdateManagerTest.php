<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Services\Update\Deployer;
use App\Services\Update\UpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Update\FakeDeployer;
use Tests\TestCase;

class UpdateManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: UpdateManager, 1: FakeDeployer}
     */
    private function manager(bool $healthy = true): array
    {
        $fake = new FakeDeployer($healthy);
        $this->app->instance(Deployer::class, $fake);

        return [app(UpdateManager::class), $fake];
    }

    public function test_status_without_a_release(): void
    {
        [$manager] = $this->manager();

        $status = $manager->status();
        $this->assertSame('1.0.0', $status['current']);
        $this->assertNull($status['available']);
        $this->assertFalse($status['update_available']);
    }

    public function test_status_reports_an_available_update(): void
    {
        AppRelease::factory()->create(['version' => '2.0.0']);
        [$manager] = $this->manager();

        $status = $manager->status();
        $this->assertSame('2.0.0', $status['available']);
        $this->assertTrue($status['update_available']);
    }

    public function test_update_succeeds_on_a_healthy_deploy(): void
    {
        AppRelease::factory()->create(['version' => '2.0.0']);
        [$manager, $fake] = $this->manager(true);

        $this->assertTrue($manager->update());
        $this->assertTrue($fake->deployed);
        $this->assertFalse($fake->rolledBack);
        // Workers are restarted onto the new code as a final step, after success.
        $this->assertTrue($fake->restartedWorkers);

        $run = $manager->lastRun();
        $this->assertSame('success', $run['status']);
        $this->assertSame('2.0.0', $run['version']);
    }

    public function test_status_self_heals_when_the_running_version_already_matches(): void
    {
        // A run can be interrupted (the deploy restarts Horizon, killing the update
        // worker) after the symlink flip but before "success" is recorded. Once the web
        // serves the target version, the status must resolve to success on its own.
        AppRelease::factory()->create(['version' => '1.0.0']); // == currentVersion()
        [$manager] = $this->manager();

        $manager->markQueued(); // records 'queued' at version 1.0.0

        $this->assertSame('success', $manager->lastRun()['status']);
        $this->assertSame('success', $manager->status()['last_run']['status']);
    }

    public function test_status_times_out_a_run_stuck_far_too_long(): void
    {
        AppRelease::factory()->create(['version' => '2.0.0']); // never lands (current is 1.0.0)
        [$manager] = $this->manager();

        $manager->markQueued();
        $this->travel(25)->minutes();

        $this->assertSame('failed', $manager->lastRun()['status']);
    }

    public function test_update_auto_rolls_back_on_a_failed_health_check(): void
    {
        AppRelease::factory()->create(['version' => '2.0.0']);
        [$manager, $fake] = $this->manager(false);

        $this->assertFalse($manager->update());
        $this->assertTrue($fake->rolledBack);

        $this->assertSame('failed', $manager->lastRun()['status']);
    }

    public function test_updater_base_path_defaults_two_levels_above_the_app(): void
    {
        // Regression: the in-app updater built `<base>/releases/releases/...` because
        // the base defaulted to dirname(base_path()) (one level) instead of two. In the
        // atomic layout the app runs at <base>/releases/<ts>_<ver>, so the base — which
        // holds releases/, shared/ and the current symlink — is two levels up.
        $this->assertSame(dirname(base_path(), 2), config('updater.base_path'));
    }

    public function test_mark_queued_records_a_pending_run(): void
    {
        AppRelease::factory()->create(['version' => '2.0.0']);
        [$manager] = $this->manager();

        $manager->markQueued();

        $run = $manager->lastRun();
        $this->assertSame('queued', $run['status']);
        $this->assertSame('2.0.0', $run['version']);
        $this->assertSame('queued', $manager->status()['last_run']['status']);
    }

    public function test_update_without_a_release_is_a_noop(): void
    {
        [$manager, $fake] = $this->manager();

        $this->assertFalse($manager->update());
        $this->assertFalse($fake->deployed);
    }
}
