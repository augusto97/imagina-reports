<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountAndPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_update_their_name_and_email(): void
    {
        $user = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'email' => 'old@x.com']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/user/profile', ['name' => 'Nuevo Nombre', 'email' => 'nuevo@imaginawp.com'])
            ->assertOk()
            ->assertJsonPath('user.email', 'nuevo@imaginawp.com');

        $this->assertSame('Nuevo Nombre', $user->fresh()?->name);
        $this->assertSame('nuevo@imaginawp.com', $user->fresh()?->email);
    }

    public function test_email_must_be_unique_across_users(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->create(['agency_id' => $agency->id, 'email' => 'taken@x.com']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'email' => 'me@x.com']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/user/profile', ['name' => 'X', 'email' => 'taken@x.com'])->assertStatus(422);
    }

    public function test_re_saving_the_same_email_is_allowed(): void
    {
        $user = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'email' => 'same@x.com']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/user/profile', ['name' => 'Same', 'email' => 'same@x.com'])->assertOk();
    }

    public function test_forgot_password_sends_a_reset_notification_with_the_spa_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'email' => 'reset@x.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'reset@x.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl ?? '';

            return is_string($url) && str_contains($url, '/#/reset-password?token=') && str_contains($url, 'email=reset%40x.com');
        });
    }

    public function test_forgot_password_is_generic_for_unknown_emails(): void
    {
        Notification::fake();

        // Still 200 (no enumeration) and no notification sent.
        $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@x.com'])->assertOk();
        Notification::assertNothingSent();
    }

    public function test_reset_password_sets_a_new_password_with_a_valid_token(): void
    {
        $user = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'email' => 'r@x.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => 'r@x.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()?->password ?? ''));
    }

    public function test_reset_password_rejects_a_bad_token(): void
    {
        User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'email' => 'r@x.com']);

        $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'r@x.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422);
    }

}
