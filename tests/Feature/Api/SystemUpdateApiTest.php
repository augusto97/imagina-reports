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

    private function loginAsPlatformAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true]));
    }

    public function test_status_is_available_to_any_authenticated_user(): void
    {
        $this->loginAs(UserRole::Collaborator);

        $this->getJson('/api/v1/system/update/status')
            ->assertOk()
            ->assertJsonStructure(['current', 'available', 'update_available', 'worker_version', 'worker_checked_at']);
    }

    public function test_updates_are_forbidden_even_for_an_agency_owner(): void
    {
        // App updates are a PLATFORM concern now — a single agency (even its owner) can't.
        $this->loginAs(UserRole::Owner);

        $this->postJson('/api/v1/system/update/restart-workers')->assertForbidden();
        $this->postJson('/api/v1/system/update/run')->assertForbidden();
        $this->postJson('/api/v1/system/update/rollback')->assertForbidden();
    }

    public function test_platform_admin_can_restart_workers(): void
    {
        Queue::fake();
        $this->loginAsPlatformAdmin();

        $this->postJson('/api/v1/system/update/restart-workers')->assertStatus(202);
        Queue::assertPushed(RecordWorkerVersionJob::class);
    }

    public function test_platform_admin_can_queue_an_update(): void
    {
        Queue::fake();
        $this->loginAsPlatformAdmin();

        $this->postJson('/api/v1/system/update/run')->assertStatus(202);
        Queue::assertPushed(RunUpdateJob::class);
    }

    public function test_platform_admin_can_roll_back(): void
    {
        $fake = new FakeDeployer;
        $this->app->instance(Deployer::class, $fake);
        $this->loginAsPlatformAdmin();

        $this->postJson('/api/v1/system/update/rollback')->assertOk();
        $this->assertTrue($fake->rolledBack);
    }
}
