<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private function crowdSecSource(string $token): DataSource
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        return DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::CrowdSec,
            'config' => [],
            'credentials' => [],
            'push_token' => $token,
        ]);
    }

    public function test_a_pushed_payload_is_normalized_and_stored_as_a_snapshot(): void
    {
        $source = $this->crowdSecSource('tok-crowdsec-123');

        // No auth header — the token in the URL is the only credential (outbound push).
        $this->postJson('/api/v1/ingest/tok-crowdsec-123', [
            'alerts' => [
                ['scenario' => 'ssh-bf', 'events_count' => 6, 'source' => ['value' => '1.1.1.1', 'cn' => 'CN'], 'decisions' => [['id' => 1]]],
                ['scenario' => 'ssh-bf', 'events_count' => 4, 'source' => ['value' => '2.2.2.2', 'cn' => 'CN'], 'decisions' => [['id' => 2], ['id' => 3]]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('ir_metric_snapshots', [
            'data_source_id' => $source->id,
            'agency_id' => $source->agency_id,
            'status' => 'ok',
        ]);

        $snapshot = MetricSnapshot::query()->where('data_source_id', $source->id)->latest('id')->firstOrFail();
        $this->assertSame(2, $snapshot->payload['metrics']['crowdsec.alerts']);
        $this->assertSame(3, $snapshot->payload['metrics']['crowdsec.attacks_blocked']);

        // The source is marked synced so the admin sees it turn "ok".
        $this->assertSame('ok', $source->refresh()->status->value);
        $this->assertNotNull($source->last_synced_at);
    }

    public function test_an_unknown_push_token_is_rejected(): void
    {
        $this->postJson('/api/v1/ingest/does-not-exist', ['alerts' => []])
            ->assertNotFound();
    }

    public function test_a_non_push_source_rejects_pushed_data(): void
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp, // not a ReceivesPushedData connector
            'config' => [],
            'credentials' => [],
            'push_token' => 'tok-mainwp',
        ]);

        $this->postJson('/api/v1/ingest/tok-mainwp', ['alerts' => []])
            ->assertStatus(422);
    }
}
