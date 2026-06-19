<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
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
}
