<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    public function test_it_creates_a_site_with_a_currency(): void
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson('/api/v1/sites', [
            'client_id' => $client->id,
            'name' => 'Tienda',
            'url' => 'https://tienda.co',
            'currency' => 'COP',
        ])
            ->assertCreated()
            ->assertJsonPath('currency', 'COP');
    }

    public function test_it_rejects_an_unsupported_currency(): void
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson('/api/v1/sites', [
            'client_id' => $client->id,
            'name' => 'Tienda',
            'url' => 'https://tienda.co',
            'currency' => 'XYZ',
        ])->assertJsonValidationErrorFor('currency');
    }

    public function test_it_updates_a_site_currency_and_name(): void
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id, 'currency' => 'USD']);

        $this->putJson("/api/v1/sites/{$site->id}", ['name' => 'Nuevo nombre', 'currency' => 'PEN'])
            ->assertOk()
            ->assertJsonPath('name', 'Nuevo nombre')
            ->assertJsonPath('currency', 'PEN');

        $this->assertDatabaseHas('ir_sites', ['id' => $site->id, 'name' => 'Nuevo nombre', 'currency' => 'PEN']);
    }

    public function test_it_cannot_update_another_agencys_site(): void
    {
        $other = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $other->id]);
        $site = Site::factory()->create(['agency_id' => $other->id, 'client_id' => $client->id]);

        $this->putJson("/api/v1/sites/{$site->id}", ['currency' => 'CLP'])->assertNotFound();
    }

    public function test_it_deletes_a_site_and_cascades_its_data(): void
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id]);
        $snapshot = MetricSnapshot::factory()->create(['agency_id' => $this->agency->id, 'data_source_id' => $source->id]);

        $this->deleteJson("/api/v1/sites/{$site->id}")->assertNoContent();

        $this->assertDatabaseMissing('ir_sites', ['id' => $site->id]);
        // The site's data source (no DB FK on site_id) and its snapshots are cleaned up too.
        $this->assertDatabaseMissing('ir_data_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('ir_metric_snapshots', ['id' => $snapshot->id]);
    }

    public function test_it_cannot_delete_another_agencys_site(): void
    {
        $other = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $other->id]);
        $site = Site::factory()->create(['agency_id' => $other->id, 'client_id' => $client->id]);

        $this->deleteJson("/api/v1/sites/{$site->id}")->assertNotFound();
        $this->assertDatabaseHas('ir_sites', ['id' => $site->id]);
    }
}
