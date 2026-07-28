<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Connect\ResourceDiscovery;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What happens after a one-click connect when the account turns out to expose nothing we
 * can read. This used to be silent: the source kept a valid token, no property id and no
 * explanation, and the client only found out when "Probar" said the id was missing.
 */
class ResourceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function instagramSource(): DataSource
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        return DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::Instagram,
            'config' => [],
            'credentials' => ['access_token' => 'tok'],
        ]);
    }

    public function test_an_account_with_nothing_to_pick_explains_why(): void
    {
        // Authorized fine, but no Facebook Page has a linked Instagram business account.
        Http::fake(['graph.facebook.com/*/me/accounts*' => Http::response(['data' => []])]);
        $source = $this->instagramSource();

        $error = app(ResourceDiscovery::class)->discover($source);

        $this->assertNotNull($error);
        $this->assertStringContainsString('Business o Creator', $error);
        // …and it's recorded, so the row says it without pressing anything.
        $this->assertSame($error, $source->refresh()->last_error);
    }

    public function test_an_unreachable_provider_says_so_instead_of_staying_quiet(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(null, 500)]);
        $source = $this->instagramSource();

        $error = app(ResourceDiscovery::class)->discover($source);

        $this->assertNotNull($error);
        $this->assertStringContainsString('Detectar cuentas', (string) $source->refresh()->last_error);
    }

    public function test_a_single_account_is_selected_automatically(): void
    {
        Http::fake(['graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [['name' => 'Acme', 'instagram_business_account' => ['id' => '178414', 'username' => 'acme']]],
        ])]);
        $source = $this->instagramSource();

        $this->assertNull(app(ResourceDiscovery::class)->discover($source));

        $source->refresh();
        $this->assertSame('178414', $source->config['ig_user_id'] ?? null);
        $this->assertNull($source->last_error);
    }

    public function test_several_accounts_leave_a_picker_for_the_client(): void
    {
        Http::fake(['graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                ['name' => 'Acme', 'instagram_business_account' => ['id' => '1', 'username' => 'acme']],
                ['name' => 'Other', 'instagram_business_account' => ['id' => '2', 'username' => 'other']],
            ],
        ])]);
        $source = $this->instagramSource();

        $this->assertNull(app(ResourceDiscovery::class)->discover($source));

        $options = $source->refresh()->meta['connect_options'] ?? null;
        $this->assertIsArray($options);
        $this->assertSame('ig_user_id', $options['field'] ?? null);
        $this->assertCount(2, $options['options'] ?? []);
    }

    public function test_the_endpoint_lets_the_client_retry_without_reconnecting(): void
    {
        Http::fake(['graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [['name' => 'Acme', 'instagram_business_account' => ['id' => '178414', 'username' => 'acme']]],
        ])]);
        $source = $this->instagramSource();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $source->agency_id]));

        $this->postJson("/api/v1/data-sources/{$source->id}/discover")
            ->assertOk()
            ->assertJsonPath('successful', true);

        $this->assertSame('178414', $source->refresh()->config['ig_user_id'] ?? null);
    }

    public function test_the_endpoint_is_tenant_scoped(): void
    {
        $source = $this->instagramSource();
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->postJson("/api/v1/data-sources/{$source->id}/discover")->assertNotFound();
    }
}
