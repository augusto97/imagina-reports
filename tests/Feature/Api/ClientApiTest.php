<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAgency(): Agency
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        return $agency;
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/clients')->assertUnauthorized();
    }

    public function test_index_lists_only_the_current_agencys_clients(): void
    {
        $agency = $this->actingAsAgency();
        $mine = Client::factory()->create(['agency_id' => $agency->id]);
        Client::factory()->create(['agency_id' => Agency::factory()->create()->id]);

        $this->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id);
    }

    public function test_store_creates_a_client_scoped_to_the_agency(): void
    {
        $agency = $this->actingAsAgency();

        $this->postJson('/api/v1/clients', ['name' => 'Acme', 'contact_email' => 'a@acme.test'])
            ->assertCreated()
            ->assertJsonPath('name', 'Acme');

        $this->assertDatabaseHas('ir_clients', ['name' => 'Acme', 'agency_id' => $agency->id]);
    }

    public function test_store_validates_input(): void
    {
        $this->actingAsAgency();

        $this->postJson('/api/v1/clients', [])->assertUnprocessable();
    }

    public function test_it_cannot_view_another_agencys_client(): void
    {
        $this->actingAsAgency();
        $other = Client::factory()->create(['agency_id' => Agency::factory()->create()->id]);

        $this->getJson("/api/v1/clients/{$other->id}")->assertNotFound();
    }

    public function test_update_edits_a_client(): void
    {
        $agency = $this->actingAsAgency();
        $client = Client::factory()->create(['agency_id' => $agency->id, 'name' => 'Antiguo']);

        $this->putJson("/api/v1/clients/{$client->id}", ['name' => 'Nuevo', 'contact_email' => 'n@x.test'])
            ->assertOk()
            ->assertJsonPath('name', 'Nuevo');

        $this->assertDatabaseHas('ir_clients', ['id' => $client->id, 'name' => 'Nuevo']);
    }

    public function test_destroy_removes_a_client_without_sites(): void
    {
        $agency = $this->actingAsAgency();
        $client = Client::factory()->create(['agency_id' => $agency->id]);

        $this->deleteJson("/api/v1/clients/{$client->id}")->assertNoContent();
        $this->assertDatabaseMissing('ir_clients', ['id' => $client->id]);
    }

    public function test_destroy_is_refused_when_the_client_has_sites(): void
    {
        $agency = $this->actingAsAgency();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        $this->deleteJson("/api/v1/clients/{$client->id}")->assertStatus(422);
        $this->assertDatabaseHas('ir_clients', ['id' => $client->id]);
    }

    public function test_it_cannot_update_another_agencys_client(): void
    {
        $this->actingAsAgency();
        $other = Client::factory()->create(['agency_id' => Agency::factory()->create()->id]);

        $this->putJson("/api/v1/clients/{$other->id}", ['name' => 'Hack'])->assertNotFound();
    }
}
