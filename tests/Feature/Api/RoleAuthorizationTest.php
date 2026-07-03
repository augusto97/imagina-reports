<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A collaborator is the low-trust role: administrative, financial and destructive actions
 * are owner/admin only. These lock in that gating so a future change can't silently reopen it.
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private function actAs(UserRole $role): void
    {
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'role' => $role]));
    }

    public function test_a_collaborator_cannot_change_agency_settings_or_webhooks(): void
    {
        $this->actAs(UserRole::Collaborator);

        $this->putJson('/api/v1/agency', ['name' => 'X', 'webhook_urls' => ['https://evil.test/hook'], 'webhook_secret' => 'stolen'])
            ->assertForbidden();
        $this->postJson('/api/v1/agency/webhooks/test')->assertForbidden();
    }

    public function test_a_collaborator_cannot_manage_data_sources(): void
    {
        $this->actAs(UserRole::Collaborator);
        $site = Site::factory()->create(['agency_id' => $this->agency->id]);
        $source = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id]);

        $this->postJson("/api/v1/sites/{$site->id}/data-sources", ['type' => 'ga4', 'config' => []])->assertForbidden();
        $this->deleteJson("/api/v1/data-sources/{$source->id}")->assertForbidden();
    }

    public function test_a_collaborator_cannot_subscribe_or_delete(): void
    {
        $this->actAs(UserRole::Collaborator);
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        $this->postJson('/api/v1/billing/subscribe', ['provider' => 'mercadopago', 'plan_id' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/sites/{$site->id}")->assertForbidden();
    }

    public function test_an_owner_can_do_all_of_it(): void
    {
        // Sanity: the gate lets a privileged user through (branding save succeeds).
        $this->actAs(UserRole::Owner);

        $this->putJson('/api/v1/agency', ['name' => 'Mi Agencia', 'default_locale' => 'es'])->assertOk();
    }
}
