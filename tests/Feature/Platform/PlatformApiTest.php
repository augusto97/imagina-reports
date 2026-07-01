<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPlatformAdmin(): User
    {
        $admin = User::factory()->create(['agency_id' => null, 'is_platform_admin' => true, 'role' => UserRole::Owner]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_a_normal_agency_user_cannot_reach_the_platform_panel(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson('/api/v1/platform/agencies')->assertForbidden();
        $this->getJson('/api/v1/platform/plans')->assertForbidden();
    }

    public function test_platform_admin_lists_all_agencies_with_usage(): void
    {
        $this->actAsPlatformAdmin();
        Agency::factory()->count(2)->create();

        $this->getJson('/api/v1/platform/agencies')
            ->assertOk()
            ->assertJsonStructure(['*' => ['id', 'name', 'status', 'usage' => ['sites', 'clients'], 'limits']]);
    }

    public function test_platform_admin_creates_an_agency_with_an_owner(): void
    {
        $this->actAsPlatformAdmin();
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/platform/agencies', [
            'name' => 'Nueva Agencia',
            'plan_id' => $plan->id,
            'owner_name' => 'Dueño',
            'owner_email' => 'owner@nueva.test',
            'owner_password' => 'secret123',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Nueva Agencia')
            ->assertJsonPath('plan.id', $plan->id);

        $this->assertDatabaseHas('ir_users', ['email' => 'owner@nueva.test', 'role' => 'owner']);
    }

    public function test_platform_admin_assigns_a_plan_and_suspends(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();
        $plan = Plan::factory()->create();

        $this->putJson("/api/v1/platform/agencies/{$agency->id}", ['plan_id' => $plan->id, 'status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('plan.id', $plan->id);
    }

    public function test_platform_admin_can_crud_plans(): void
    {
        $this->actAsPlatformAdmin();

        $id = $this->postJson('/api/v1/platform/plans', ['name' => 'Custom', 'max_sites' => 7])
            ->assertCreated()
            ->assertJsonPath('slug', 'custom')
            ->json('id');

        $this->putJson("/api/v1/platform/plans/{$id}", ['max_sites' => 12])->assertOk()->assertJsonPath('max_sites', 12);
        $this->deleteJson("/api/v1/platform/plans/{$id}")->assertNoContent();
    }

    public function test_impersonation_scopes_the_platform_admin_to_the_agency(): void
    {
        $admin = $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();

        $this->postJson("/api/v1/platform/agencies/{$agency->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('impersonating', $agency->id);
        $this->assertSame($agency->id, $admin->refresh()->impersonating_agency_id);

        // While impersonating, the auth profile reports the active agency.
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('user.impersonating', $agency->id);

        $this->postJson('/api/v1/platform/stop-impersonate')->assertOk()->assertJsonPath('impersonating', null);
        $this->assertNull($admin->refresh()->impersonating_agency_id);
    }
}
