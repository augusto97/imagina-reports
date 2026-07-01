<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\AnomalyAlert;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnomalyApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function makeAlert(?Agency $agency = null): AnomalyAlert
    {
        $agency ??= $this->agency;
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        return AnomalyAlert::query()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'report_id' => null,
            'type' => 'traffic_drop',
            'metric' => 'ga4.sessions',
            'current_value' => 300,
            'previous_value' => 1000,
            'change_percent' => -70,
        ]);
    }

    public function test_index_lists_the_agencys_anomalies(): void
    {
        $this->makeAlert();

        $this->getJson('/api/v1/anomalies')
            ->assertOk()
            ->assertJsonPath('0.type', 'traffic_drop')
            ->assertJsonPath('0.change_percent', -70);
    }

    public function test_it_does_not_list_another_agencys_anomalies(): void
    {
        $this->makeAlert(Agency::factory()->create());

        $this->getJson('/api/v1/anomalies')->assertOk()->assertJsonCount(0);
    }

    public function test_acknowledge_marks_it_resolved(): void
    {
        $alert = $this->makeAlert();

        $this->postJson("/api/v1/anomalies/{$alert->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('acknowledged_at', fn (?string $value): bool => $value !== null);

        $this->assertNotNull($alert->refresh()->acknowledged_at);
    }

    public function test_destroy_removes_it(): void
    {
        $alert = $this->makeAlert();

        $this->deleteJson("/api/v1/anomalies/{$alert->id}")->assertNoContent();
        $this->assertDatabaseMissing('ir_anomalies', ['id' => $alert->id]);
    }

    public function test_it_cannot_acknowledge_another_agencys_anomaly(): void
    {
        $alert = $this->makeAlert(Agency::factory()->create());

        $this->postJson("/api/v1/anomalies/{$alert->id}/acknowledge")->assertNotFound();
    }
}
