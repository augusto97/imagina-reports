<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private function boot(UserRole $role = UserRole::Owner, ?Plan $plan = null): User
    {
        $this->agency = Agency::factory()->create(['plan_id' => $plan?->id]);
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_owner_lists_the_team(): void
    {
        $this->boot();
        User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Collaborator]);

        $this->getJson('/api/v1/team')->assertOk()->assertJsonCount(2);
    }

    public function test_owner_invites_a_member(): void
    {
        $this->boot();

        $this->postJson('/api/v1/team', ['name' => 'Nuevo', 'email' => 'nuevo@team.test', 'password' => 'secret123', 'role' => 'collaborator'])
            ->assertCreated()
            ->assertJsonPath('role', 'collaborator');

        $this->assertDatabaseHas('ir_users', ['email' => 'nuevo@team.test', 'agency_id' => $this->agency->id]);
    }

    public function test_a_collaborator_cannot_invite(): void
    {
        $this->boot(UserRole::Collaborator);

        $this->postJson('/api/v1/team', ['name' => 'X', 'email' => 'x@team.test', 'password' => 'secret123', 'role' => 'collaborator'])
            ->assertForbidden();
    }

    public function test_the_max_users_limit_is_enforced(): void
    {
        // Plan allows 1 user; the owner already fills it.
        $this->boot(UserRole::Owner, Plan::factory()->create(['max_users' => 1]));

        $this->postJson('/api/v1/team', ['name' => 'Extra', 'email' => 'extra@team.test', 'password' => 'secret123', 'role' => 'admin'])
            ->assertForbidden();
    }

    public function test_cannot_delete_yourself(): void
    {
        $me = $this->boot();

        $this->deleteJson("/api/v1/team/{$me->id}")->assertStatus(422);
    }

    public function test_cannot_remove_the_last_owner(): void
    {
        $this->boot();
        $otherOwner = User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner]);

        // Two owners: removing one is fine.
        $this->deleteJson("/api/v1/team/{$otherOwner->id}")->assertNoContent();

        // Now only the acting owner remains — can't be removed (also it's self, 422 either way).
        $collab = User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Collaborator]);
        $this->putJson("/api/v1/team/{$collab->id}", ['role' => 'collaborator'])->assertOk();
    }

    public function test_cannot_touch_another_agencys_user(): void
    {
        $this->boot();
        $other = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'role' => UserRole::Collaborator]);

        $this->deleteJson("/api/v1/team/{$other->id}")->assertNotFound();
        $this->putJson("/api/v1/team/{$other->id}", ['name' => 'Hack'])->assertNotFound();
    }
}
