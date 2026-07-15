<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\PlatformMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformMailApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPlatformAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true, 'role' => UserRole::Owner]));
    }

    public function test_it_saves_smtp_settings_with_the_password_encrypted(): void
    {
        $this->actAsPlatformAdmin();

        $this->putJson('/api/v1/platform/mail-settings', [
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.imaginawp.com',
            'mail_port' => 587,
            'mail_username' => 'reportes@imaginawp.com',
            'mail_password' => 'super-secret',
            'mail_scheme' => 'tls',
            'mail_from_address' => 'reportes@imaginawp.com',
            'mail_from_name' => 'Imagina Reports',
        ])->assertOk()->assertJson([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.imaginawp.com',
            'mail_password_set' => true,
            'mail_sends' => true,
        ]);

        // The password is stored encrypted, not in plaintext.
        $settings = PlatformSetting::current();
        $this->assertNotSame('super-secret', ($settings->settings ?? [])[PlatformMail::PASSWORD] ?? null);
        $this->assertSame('super-secret', $settings->secret(PlatformMail::PASSWORD));
    }

    public function test_apply_overrides_the_runtime_mail_config(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.from.address' => 'old@x.com']);

        $settings = PlatformSetting::current();
        $settings->put(PlatformMail::MAILER, 'smtp');
        $settings->put(PlatformMail::HOST, 'smtp.example.com');
        $settings->put(PlatformMail::FROM_ADDRESS, 'new@imaginawp.com');
        $settings->putSecret(PlatformMail::PASSWORD, 'pw');
        $settings->save();

        PlatformMail::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('new@imaginawp.com', config('mail.from.address'));
        $this->assertSame('pw', config('mail.mailers.smtp.password'));
    }

    public function test_the_test_endpoint_sends_a_mail(): void
    {
        Mail::fake(); // no real transport → the raw send just succeeds
        $this->actAsPlatformAdmin();

        $this->postJson('/api/v1/platform/mail-settings/test', ['to' => 'me@imaginawp.com'])
            ->assertOk()
            ->assertJson(['sent' => true]);
    }

    public function test_it_is_platform_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson('/api/v1/platform/mail-settings')->assertForbidden();
    }
}
