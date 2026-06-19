<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\DataSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Connectors\FakeConnector;
use Tests\TestCase;

class DataSourceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);

        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'type' => DataSourceType::MainWp,
            'credentials' => ['token' => 'super-secret-token'],
        ]);

        // Decrypted via the cast.
        $this->assertSame('super-secret-token', $source->fresh()?->credentials['token']);

        // Encrypted at rest — the raw column never contains the plaintext.
        $raw = (string) DB::table('ir_data_sources')->where('id', $source->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-token', $raw);
    }

    public function test_data_sources_are_agency_scoped(): void
    {
        $agencyA = Agency::factory()->create();
        $agencyB = Agency::factory()->create();

        DataSource::factory()->create(['agency_id' => $agencyA->id]);
        DataSource::factory()->create(['agency_id' => $agencyB->id]);

        app(TenantContext::class)->set($agencyA->id);

        $this->assertSame(1, DataSource::query()->count());
    }

    public function test_registry_resolves_the_connector_for_a_data_source(): void
    {
        $registry = app(ConnectorRegistry::class);
        $registry->register(new FakeConnector(DataSourceType::MainWp->value, 'MainWP'));

        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'type' => DataSourceType::MainWp,
        ]);

        $this->assertSame(DataSourceType::MainWp->value, $registry->for($source)->key());
    }
}
