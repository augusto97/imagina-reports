<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Jobs\RecordWorkerVersionJob;
use App\Jobs\RunUpdateJob;
use App\Models\Agency;
use App\Models\User;
use App\Services\Update\Deployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Update\FakeDeployer;
use Tests\TestCase;

class SystemUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(UserRole $role): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => $role]));
    }

    public function test_status_is_available_to_any_authenticated_user(): void
    {
        $this->loginAs(UserRole::Collaborator);

        $this->getJson('/api/v1/system/update/status')
            ->assertOk()
            ->assertJsonStructure(['current', 'available', 'update_available', 'worker_version', 'worker_checked_at']);
    }

    public function test_restart_workers_is_forbidden_for_non_privileged_users(): void
    {
        $this->loginAs(UserRole::Collaborator);

        $this->postJson('/api/v1/system/update/restart-workers')->assertForbidden();
    }

    public function test_a_privileged_user_can_restart_workers(): void
    {
        Queue::fake();
        $this->loginAs(UserRole::Owner);

        $this->postJson('/api/v1/system/update/restart-workers')->assertStatus(202);
        Queue::assertPushed(RecordWorkerVersionJob::class);
    }

    public function test_run_is_forbidden_for_non_privileged_users(): void
    {
        Queue::fake();
        $this->loginAs(UserRole::Collaborator);

        $this->postJson('/api/v1/system/update/run')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_a_privileged_user_can_queue_an_update(): void
    {
        Queue::fake();
        $this->loginAs(UserRole::Owner);

        $this->postJson('/api/v1/system/update/run')->assertStatus(202);
        Queue::assertPushed(RunUpdateJob::class);
    }

    public function test_a_privileged_user_can_roll_back(): void
    {
        $fake = new FakeDeployer;
        $this->app->instance(Deployer::class, $fake);
        $this->loginAs(UserRole::Admin);

        $this->postJson('/api/v1/system/update/rollback')->assertOk();
        $this->assertTrue($fake->rolledBack);
    }
}
