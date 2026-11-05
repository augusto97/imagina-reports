<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Connectors\Period;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Enums\UserRole;
use App\Jobs\NotifySourceFailure;
use App\Mail\ReconnectRequestMail;
use App\Mail\SourceFailedMail;
use App\Models\Agency;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use App\Services\SyncService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Connectors\FakeConnector;
use Tests\TestCase;

/**
 * Etapa 5: a connection that drops is reported once, and the client can be asked to reconnect
 * it from a link.
 */
class SourceFailureTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_oauth.client_id' => 'cid.apps.googleusercontent.com', 'services.google_oauth.client_secret' => 'secret']);
        $this->agency = Agency::factory()->create(['name' => 'Agencia Norte']);
        $client = Client::factory()->create(['agency_id' => $this->agency->id, 'contact_email' => 'laura@cliente.com']);
        $this->site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id, 'name' => 'Clínica Sonrisa']);
        User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner, 'email' => 'owner@norte.test']);
    }

    private function source(DataSourceType $type, DataSourceStatus $status = DataSourceStatus::Ok): DataSource
    {
        return DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id, 'type' => $type, 'status' => $status]);
    }

    private function sync(DataSource $source, MetricSet $result): void
    {
        app(ConnectorRegistry::class)->register(new FakeConnector($source->type->value, $source->type->label(), $result));
        app(TenantContext::class)->set($this->agency->id);
        app(SyncService::class)->sync($source, Period::make('2026-06-01', '2026-06-30'));
    }

    public function test_a_source_that_stops_working_is_reported_once(): void
    {
        Queue::fake();
        $source = $this->source(DataSourceType::Ga4);

        $this->sync($source, MetricSet::failed('Google respondió HTTP 401: invalid_grant'));
        $this->sync($source->refresh(), MetricSet::failed('Google respondió HTTP 401: invalid_grant'));

        Queue::assertPushed(NotifySourceFailure::class, 1);
        $this->assertSame(DataSourceStatus::Error, $source->refresh()->status);
    }

    public function test_a_healthy_sync_says_nothing(): void
    {
        Queue::fake();
        $this->sync($this->source(DataSourceType::Ga4), MetricSet::ok(['ga4.sessions' => 10]));

        Queue::assertNotPushed(NotifySourceFailure::class);
    }

    public function test_the_team_gets_an_email_and_the_webhook_fires(): void
    {
        Mail::fake();
        $source = $this->source(DataSourceType::Ga4, DataSourceStatus::Error);
        $source->forceFill(['last_error' => 'Google respondió HTTP 401: invalid_grant'])->save();
        $webhooks = new class implements WebhookDispatcher
        {
            /** @var array<string, array<string, mixed>> */
            public array $events = [];

            public function dispatch(int $agencyId, string $event, array $payload): void
            {
                $this->events[$event] = $payload;
            }
        };

        app()->call([new NotifySourceFailure($source->id), 'handle'], ['webhooks' => $webhooks]);

        Mail::assertSent(SourceFailedMail::class, fn (SourceFailedMail $mail): bool => $mail->hasTo('owner@norte.test')
            && $mail->reconnectable
            && str_contains($mail->render(), 'invalid_grant')
            && str_contains($mail->render(), 'reconnect='.$source->id));
        $this->assertTrue($webhooks->events['source.failed']['reconnectable']);
        $this->assertSame('Clínica Sonrisa', $webhooks->events['source.failed']['site']);
    }

    public function test_the_agency_can_turn_the_email_off(): void
    {
        Mail::fake();
        $this->agency->update(['settings' => ['notify' => ['source_failures' => false]]]);
        $source = $this->source(DataSourceType::Ga4, DataSourceStatus::Error);

        app()->call([new NotifySourceFailure($source->id), 'handle']);

        Mail::assertNothingSent();
    }

    public function test_the_client_is_asked_to_reconnect_with_a_link_for_that_source_only(): void
    {
        Mail::fake();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Collaborator]));
        $source = $this->source(DataSourceType::Ga4, DataSourceStatus::Error);

        $url = $this->postJson("/api/v1/data-sources/{$source->id}/reconnect-request", ['email' => 'Laura@Cliente.com', 'message' => 'Se venció el permiso de Google.'])
            ->assertCreated()
            ->assertJsonPath('sent_to', 'laura@cliente.com')
            ->json('url');

        $invite = ConnectInvite::query()->sole();
        $this->assertSame(['ga4'], $invite->types);
        $this->assertSame($invite->url(), $url);
        Mail::assertSent(ReconnectRequestMail::class, fn (ReconnectRequestMail $mail): bool => $mail->hasTo('laura@cliente.com')
            && str_contains($mail->render(), 'Se venció el permiso de Google.')
            && str_contains($mail->render(), $invite->url()));

        $this->getJson("/api/v1/sites/{$this->site->id}/data-sources")->assertOk()->assertJsonPath('0.reconnect_requested_to', 'laura@cliente.com');
    }

    public function test_sources_without_a_one_click_connection_cannot_be_requested(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
        $source = $this->source(DataSourceType::MainWp, DataSourceStatus::Error);

        $this->postJson("/api/v1/data-sources/{$source->id}/reconnect-request", ['email' => 'laura@cliente.com'])->assertStatus(422);
    }

    public function test_another_agency_cannot_request_a_reconnection(): void
    {
        $source = $this->source(DataSourceType::Ga4, DataSourceStatus::Error);
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->postJson("/api/v1/data-sources/{$source->id}/reconnect-request", ['email' => 'x@y.com'])->assertNotFound();
    }

    public function test_the_notification_switches_are_saved(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner]));

        $this->putJson('/api/v1/agency', ['name' => 'Agencia Norte', 'notify' => ['client_questions' => false]])
            ->assertOk()
            ->assertJsonPath('notify.client_questions', false)
            ->assertJsonPath('notify.source_failures', true);
    }
}
