<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The operator's high-privilege surface: platform vital signs, agency detail, permanent
 * agency deletion, and cross-agency user management (add / fix a role / reset a password /
 * remove). Everything here must stay platform-admin only.
 */
class PlatformOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPlatformAdmin(): User
    {
        $admin = User::factory()->create(['agency_id' => null, 'is_platform_admin' => true, 'role' => UserRole::Owner]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /* --------------------------------- Overview --------------------------------- */

    public function test_the_overview_reports_platform_wide_vital_signs(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create(['status' => 'active']);
        Agency::factory()->create(['status' => 'suspended']);
        User::factory()->create(['agency_id' => $agency->id]);
        Client::factory()->create(['agency_id' => $agency->id]);

        $this->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonPath('agencies.total', 2)
            ->assertJsonPath('agencies.active', 1)
            ->assertJsonPath('agencies.suspended', 1)
            ->assertJsonPath('users.total', 1)
            ->assertJsonPath('workload.clients', 1)
            ->assertJsonStructure(['health' => ['failing_sources', 'snapshots', 'storage_bytes'], 'billing' => ['active_subscriptions', 'past_due']]);
    }

    public function test_the_overview_is_platform_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson('/api/v1/platform/overview')->assertForbidden();
    }

    /* ------------------------------- Agency detail ------------------------------ */

    public function test_agency_detail_returns_usage_and_its_people(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();
        User::factory()->create(['agency_id' => $agency->id, 'name' => 'Ana', 'role' => UserRole::Owner]);

        $this->getJson("/api/v1/platform/agencies/{$agency->id}")
            ->assertOk()
            ->assertJsonPath('id', $agency->id)
            ->assertJsonPath('users.0.name', 'Ana')
            ->assertJsonPath('users.0.role', 'owner')
            ->assertJsonStructure(['limits', 'usage', 'subscription']);
    }

    /* ------------------------------ Agency deletion ----------------------------- */

    public function test_deleting_an_agency_requires_retyping_its_name_and_removes_its_data(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create(['name' => 'Agencia Acme']);
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $member = User::factory()->create(['agency_id' => $agency->id]);

        // The wrong name is rejected and nothing is touched.
        $this->deleteJson("/api/v1/platform/agencies/{$agency->id}", ['confirm_name' => 'Otra'])->assertStatus(422);
        $this->assertDatabaseHas('ir_agencies', ['id' => $agency->id]);

        $this->deleteJson("/api/v1/platform/agencies/{$agency->id}", ['confirm_name' => 'Agencia Acme'])->assertOk();

        $this->assertDatabaseMissing('ir_agencies', ['id' => $agency->id]);
        $this->assertDatabaseMissing('ir_clients', ['id' => $client->id]);
        $this->assertDatabaseMissing('ir_users', ['id' => $member->id]);
    }

    public function test_deleting_an_agency_clears_a_dangling_impersonation_pointer(): void
    {
        $admin = $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create(['name' => 'Temporal']);

        $this->postJson("/api/v1/platform/agencies/{$agency->id}/impersonate")->assertOk();
        $this->deleteJson("/api/v1/platform/agencies/{$agency->id}", ['confirm_name' => 'Temporal'])->assertOk();

        $this->assertNull($admin->refresh()->impersonating_agency_id);
    }

    public function test_an_agency_user_cannot_delete_an_agency(): void
    {
        $agency = Agency::factory()->create(['name' => 'Acme']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->deleteJson("/api/v1/platform/agencies/{$agency->id}", ['confirm_name' => 'Acme'])->assertForbidden();
        $this->assertDatabaseHas('ir_agencies', ['id' => $agency->id]);
    }

    /* ---------------------------- Cross-agency users ---------------------------- */

    public function test_the_operator_can_add_a_user_to_any_agency(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();

        $this->postJson("/api/v1/platform/agencies/{$agency->id}/users", [
            'name' => 'Nuevo',
            'email' => 'nuevo@acme.test',
            'password' => 'secret123',
            'role' => 'admin',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'nuevo@acme.test')
            ->assertJsonPath('role', 'admin');

        $this->assertDatabaseHas('ir_users', ['email' => 'nuevo@acme.test', 'agency_id' => $agency->id, 'role' => 'admin']);
    }

    public function test_the_operator_can_reset_a_password_and_change_a_role(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();
        User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]);
        $member = User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Collaborator]);

        // No "current password" needed — that is the whole point of the support path.
        $this->putJson("/api/v1/platform/agencies/{$agency->id}/users/{$member->id}", [
            'name' => 'Renombrado',
            'role' => 'admin',
            'password' => 'una-nueva-clave',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Renombrado')
            ->assertJsonPath('role', 'admin');

        $this->assertTrue(Hash::check('una-nueva-clave', $member->fresh()?->password ?? ''));
        $this->assertDatabaseHas('ir_audit_logs', ['agency_id' => $agency->id, 'action' => 'account.password_changed']);
    }

    public function test_the_last_owner_of_an_agency_is_protected(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();
        $owner = User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]);

        $this->putJson("/api/v1/platform/agencies/{$agency->id}/users/{$owner->id}", ['role' => 'admin'])->assertStatus(422);
        $this->deleteJson("/api/v1/platform/agencies/{$agency->id}/users/{$owner->id}")->assertStatus(422);
        $this->assertDatabaseHas('ir_users', ['id' => $owner->id, 'role' => 'owner']);
    }

    public function test_a_user_can_be_removed_and_only_through_their_own_agency(): void
    {
        $this->actAsPlatformAdmin();
        $agency = Agency::factory()->create();
        $other = Agency::factory()->create();
        User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]);
        $member = User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Collaborator]);

        // Route-model binding isn't tenant-scoped for users: the mismatch must 404.
        $this->deleteJson("/api/v1/platform/agencies/{$other->id}/users/{$member->id}")->assertNotFound();

        $this->deleteJson("/api/v1/platform/agencies/{$agency->id}/users/{$member->id}")->assertNoContent();
        $this->assertDatabaseMissing('ir_users', ['id' => $member->id]);
    }

    public function test_user_management_is_platform_admin_only(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->getJson("/api/v1/platform/agencies/{$agency->id}/users")->assertForbidden();
    }
}
