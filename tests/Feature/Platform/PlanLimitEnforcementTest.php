<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private function bootAgency(Plan $plan): void
    {
        $this->agency = Agency::factory()->create(['plan_id' => $plan->id]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    public function test_site_creation_is_blocked_at_the_plan_limit(): void
    {
        $this->bootAgency(Plan::factory()->create(['max_sites' => 1]));
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        $this->postJson('/api/v1/sites', ['client_id' => $client->id, 'name' => 'Otro', 'url' => 'https://otro.test'])
            ->assertForbidden();
    }

    public function test_site_creation_is_allowed_under_the_limit(): void
    {
        $this->bootAgency(Plan::factory()->create(['max_sites' => 5]));
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson('/api/v1/sites', ['client_id' => $client->id, 'name' => 'Sitio', 'url' => 'https://sitio.test'])
            ->assertCreated();
    }

    public function test_a_connector_not_in_the_plan_is_rejected(): void
    {
        $this->bootAgency(Plan::factory()->create(['allowed_connectors' => ['mainwp']]));
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        $this->postJson("/api/v1/sites/{$site->id}/data-sources", ['type' => 'ga4', 'config' => ['property_id' => '123'], 'credentials' => []])
            ->assertForbidden();
    }

    public function test_ai_builder_is_gated_by_the_plan_feature(): void
    {
        $this->bootAgency(Plan::factory()->create(['features' => ['ai_builder' => false]]));
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        $this->postJson("/api/v1/sites/{$site->id}/ai-template", ['prompt' => 'foco en SEO'])
            ->assertForbidden();
    }
}
