<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use App\Support\TwoFactor\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Account-security surface: verified email changes, TOTP two-factor, the audit trail and
 * agency self-deletion.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Owner, string $password = 'secret123'): User
    {
        return User::factory()->create([
            'agency_id' => Agency::factory()->create()->id,
            'role' => $role,
            'password' => Hash::make($password),
        ]);
    }

    /* ------------------------------- Email change ------------------------------- */

    public function test_an_email_change_is_pending_until_confirmed_from_the_new_inbox(): void
    {
        Notification::fake();
        $user = $this->user();
        Sanctum::actingAs($user);
        $original = $user->email;

        $this->putJson('/api/v1/user/profile', [
            'name' => $user->name,
            'email' => 'nuevo@imaginawp.com',
            'current_password' => 'secret123',
        ])->assertOk();

        // The login email must NOT change yet.
        $this->assertSame($original, $user->fresh()?->email);
        $this->assertSame('nuevo@imaginawp.com', $user->fresh()?->pending_email);
        Notification::assertSentOnDemand(VerifyPendingEmail::class);

        // Confirming with the token applies it.
        $token = $user->fresh()?->pending_email_token;
        $this->postJson('/api/v1/verify-email', ['token' => $token])->assertOk();

        $this->assertSame('nuevo@imaginawp.com', $user->fresh()?->email);
        $this->assertNull($user->fresh()?->pending_email);
    }

    public function test_verify_email_rejects_an_unknown_token(): void
    {
        $this->postJson('/api/v1/verify-email', ['token' => 'nope'])->assertStatus(422);
    }

    /* --------------------------------- Two-factor -------------------------------- */

    public function test_two_factor_enrolment_requires_a_valid_code_and_then_gates_login(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $secret = $this->postJson('/api/v1/user/two-factor')->assertOk()->json('secret');
        $this->assertIsString($secret);

        // Not enabled until confirmed, and a wrong code doesn't enable it.
        $this->assertFalse($user->fresh()?->hasTwoFactorEnabled());
        $this->postJson('/api/v1/user/two-factor/confirm', ['code' => '000000'])->assertStatus(422);

        // A real code from the same secret confirms it and returns recovery codes.
        $response = $this->postJson('/api/v1/user/two-factor/confirm', ['code' => $this->codeFor($secret)])->assertOk();
        $this->assertNotEmpty($response->json('recovery_codes'));
        $this->assertTrue($user->fresh()?->hasTwoFactorEnabled());
    }

    public function test_login_asks_for_the_second_factor_and_accepts_a_recovery_code(): void
    {
        $user = $this->user();
        $secret = Totp::generateSecret();
        $codes = Totp::generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Password alone: the challenge, and NO session is opened.
        $this->withSession([])
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJson(['two_factor_required' => true]);
        $this->assertGuest();

        // A wrong code is rejected.
        $this->withSession([])
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret123', 'two_factor_code' => '000000'])
            ->assertStatus(422);
        $this->assertGuest();

        // A recovery code works and is burned.
        $this->withSession([])
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret123', 'two_factor_code' => $codes[0]])
            ->assertOk();
        $this->assertCount(count($codes) - 1, $user->fresh()?->two_factor_recovery_codes ?? []);
    }

    public function test_disabling_two_factor_requires_the_password(): void
    {
        $user = $this->user();
        $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/user/two-factor', ['current_password' => 'wrong'])->assertStatus(422);
        $this->assertTrue($user->fresh()?->hasTwoFactorEnabled());

        $this->deleteJson('/api/v1/user/two-factor', ['current_password' => 'secret123'])->assertOk();
        $this->assertFalse($user->fresh()?->hasTwoFactorEnabled());
    }

    /* --------------------------------- Audit log --------------------------------- */

    public function test_sensitive_actions_are_recorded_and_only_privileged_users_can_read_them(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/user/password', [
            'current_password' => 'secret123',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertOk();

        $this->assertDatabaseHas('ir_audit_logs', ['agency_id' => $user->agency_id, 'action' => 'account.password_changed']);

        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);

        // A collaborator must not read the trail.
        Sanctum::actingAs(User::factory()->create(['agency_id' => $user->agency_id, 'role' => UserRole::Collaborator]));
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    /* ------------------------------ Agency deletion ------------------------------ */

    public function test_only_the_owner_can_delete_the_agency_and_must_confirm_password_and_name(): void
    {
        $owner = $this->user();
        $agency = Agency::query()->findOrFail($owner->agency_id);
        Sanctum::actingAs($owner);

        // Wrong password / wrong name → rejected.
        $this->deleteJson('/api/v1/agency', ['current_password' => 'wrong', 'confirm_name' => $agency->name])->assertStatus(422);
        $this->deleteJson('/api/v1/agency', ['current_password' => 'secret123', 'confirm_name' => 'otra cosa'])->assertStatus(422);
        $this->assertDatabaseHas('ir_agencies', ['id' => $agency->id]);

        $this->deleteJson('/api/v1/agency', ['current_password' => 'secret123', 'confirm_name' => $agency->name])->assertOk();
        $this->assertDatabaseMissing('ir_agencies', ['id' => $agency->id]);
    }

    public function test_an_admin_cannot_delete_the_agency(): void
    {
        $admin = $this->user(UserRole::Admin);
        Sanctum::actingAs($admin);
        $agency = Agency::query()->findOrFail($admin->agency_id);

        $this->deleteJson('/api/v1/agency', ['current_password' => 'secret123', 'confirm_name' => $agency->name])
            ->assertForbidden();
    }

    /* ----------------------------------- TOTP ----------------------------------- */

    public function test_totp_accepts_the_current_code_and_rejects_a_stale_one(): void
    {
        $secret = Totp::generateSecret();

        $this->assertTrue(Totp::verify($secret, $this->codeFor($secret)));
        $this->assertFalse(Totp::verify($secret, '123456'));
        $this->assertFalse(Totp::verify($secret, 'not-a-code'));
    }

    /** The code the user's authenticator app would show right now. */
    private function codeFor(string $secret): string
    {
        return Totp::currentCode($secret);
    }
}
