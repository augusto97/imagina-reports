<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The non-negotiable multi-tenancy guarantee (CLAUDE.md §14): one agency can
 * never read another agency's data through the AgencyScope global scope.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_queries_are_scoped_to_the_bound_agency(): void
    {
        $agencyA = Agency::factory()->create();
        $agencyB = Agency::factory()->create();

        Client::factory()->count(2)->create(['agency_id' => $agencyA->id]);
        Client::factory()->count(3)->create(['agency_id' => $agencyB->id]);

        $this->tenant()->set($agencyA->id);
        $this->assertSame(2, Client::query()->count());

        $this->tenant()->set($agencyB->id);
        $this->assertSame(3, Client::query()->count());
    }

    public function test_agency_cannot_read_another_agencys_record_by_id(): void
    {
        $agencyA = Agency::factory()->create();
        $agencyB = Agency::factory()->create();

        $clientB = Client::factory()->create(['agency_id' => $agencyB->id]);

        $this->tenant()->set($agencyA->id);

        $this->assertNull(Client::query()->find($clientB->id));
        $this->assertSame(0, Client::query()->whereKey($clientB->id)->count());
    }

    public function test_agency_id_is_stamped_from_the_bound_tenant_on_create(): void
    {
        $agency = Agency::factory()->create();

        $this->tenant()->set($agency->id);

        $client = Client::query()->create(['name' => 'Acme Co']);

        $this->assertSame($agency->id, $client->agency_id);
    }

    public function test_scope_is_inactive_when_no_tenant_is_bound(): void
    {
        $agencyA = Agency::factory()->create();
        $agencyB = Agency::factory()->create();

        Client::factory()->create(['agency_id' => $agencyA->id]);
        Client::factory()->create(['agency_id' => $agencyB->id]);

        $this->tenant()->forget();

        // Unscoped (CLI/seed) context sees every agency's data deliberately.
        $this->assertSame(2, Client::query()->count());
    }
}
