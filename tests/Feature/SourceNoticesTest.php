<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Connectors\Period;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Enums\SourceEvent;
use App\Enums\UserRole;
use App\Jobs\NotifySourceFailure;
use App\Mail\SourceActivityMail;
use App\Mail\SourceFailedMail;
use App\Models\Agency;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use App\Services\SyncService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Connectors\FakeConnector;
use Tests\TestCase;

/**
 * Each team member hears about the clients' connections — connected, stopped working, working
 * again, removed — in their bell and, if they want, by email, only for the sites they chose.
 */
class SourceNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Site $site;

    private Site $otherSite;

    private User $owner;

    private User $admin;

    private User $collaborator;

    /** @var array<string, list<array<string, mixed>>> */
    private array $webhooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Google one-click connection configured, so a failing GA4 offers «ask the client to reconnect».
        config(['services.google_oauth.client_id' => 'cid.apps.googleusercontent.com', 'services.google_oauth.client_secret' => 'secret']);
        $this->agency = Agency::factory()->create(['name' => 'Agencia Norte']);
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $this->site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id, 'name' => 'Clínica Sonrisa']);
        $this->otherSite = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id, 'name' => 'Tienda Sol']);
        $this->owner = User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner, 'name' => 'Ana Ruiz', 'email' => 'ana@norte.test']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Admin, 'email' => 'beto@norte.test']);
        $this->collaborator = User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Collaborator, 'email' => 'caro@norte.test']);

        $test = $this;
        $this->app->instance(WebhookDispatcher::class, new class($test) implements WebhookDispatcher
        {
            public function __construct(private readonly SourceNoticesTest $test) {}

            public function dispatch(int $agencyId, string $event, array $payload): void
            {
                $this->test->recordWebhook($event, $payload);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordWebhook(string $event, array $payload): void
    {
        $this->webhooks[$event][] = $payload;
    }

    /**
     * @return list<string>
     */
    private function titlesFor(User $user): array
    {
        return AppNotification::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->pluck('title')->map(static fn (mixed $title): string => (string) $title)->all();
    }

    private function source(DataSourceStatus $status = DataSourceStatus::Ok, ?Site $site = null): DataSource
    {
        return DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => ($site ?? $this->site)->id, 'type' => DataSourceType::Ga4, 'status' => $status]);
    }

    private function sync(DataSource $source, MetricSet $result): void
    {
        app(ConnectorRegistry::class)->register(new FakeConnector($source->type->value, $source->type->label(), $result));
        app(TenantContext::class)->set($this->agency->id);
        app(SyncService::class)->sync($source, Period::make('2026-06-01', '2026-06-30'));
    }

    public function test_connecting_a_source_tells_the_rest_of_the_team(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/sites/{$this->site->id}/data-sources", [
            'type' => 'mainwp',
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'secret'],
        ])->assertCreated();

        // Whoever did it isn't told about their own action.
        $this->assertSame([], $this->titlesFor($this->owner));
        $this->assertSame(['Ana Ruiz conectó MainWP en Clínica Sonrisa'], $this->titlesFor($this->admin));
        $this->assertSame(['Ana Ruiz conectó MainWP en Clínica Sonrisa'], $this->titlesFor($this->collaborator));

        // Email: owners and admins by default, collaborators only if they ask.
        Mail::assertSent(SourceActivityMail::class, 1);
        Mail::assertSent(SourceActivityMail::class, fn (SourceActivityMail $mail): bool => $mail->hasTo('beto@norte.test')
            && str_contains($mail->render(), 'Ana Ruiz conectó MainWP')
            && str_contains($mail->render(), '/admin#/clients?site='.$this->site->id));

        $this->assertSame('Ana Ruiz', $this->webhooks['source.connected'][0]['by'] ?? null);
        $this->assertSame('Clínica Sonrisa', $this->webhooks['source.connected'][0]['site'] ?? null);
    }

    public function test_a_failure_and_the_recovery_are_both_told(): void
    {
        Mail::fake();
        $source = $this->source();

        $this->sync($source, MetricSet::failed('Google respondió HTTP 401: invalid_grant'));
        $this->sync($source->refresh(), MetricSet::ok(['ga4.sessions' => 10]));
        // Healthy again and already told: nothing more.
        $this->sync($source->refresh(), MetricSet::ok(['ga4.sessions' => 12]));

        $this->assertSame([
            'Google Analytics 4 dejó de funcionar en Clínica Sonrisa',
            'Google Analytics 4 volvió a funcionar en Clínica Sonrisa',
        ], $this->titlesFor($this->collaborator));
        $failure = AppNotification::query()->withoutGlobalScopes()->where('user_id', $this->owner->id)->where('kind', SourceEvent::Failed->value)->sole();
        $this->assertSame('Google respondió HTTP 401: invalid_grant', $failure->body);
        $this->assertStringContainsString('reconnect='.$source->id, (string) $failure->link);

        // One failure email per owner/admin, each with the reconnect button.
        Mail::assertSent(SourceFailedMail::class, 2);
        Mail::assertSent(SourceActivityMail::class, fn (SourceActivityMail $mail): bool => $mail->event === SourceEvent::Recovered && $mail->hasTo('ana@norte.test'));
        $this->assertArrayHasKey('source.recovered', $this->webhooks);
        $this->assertArrayNotHasKey('failure_notified', $source->refresh()->meta ?? []);
    }

    public function test_removing_a_source_is_told_with_who_did_it(): void
    {
        Mail::fake();
        $source = $this->source();
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/data-sources/{$source->id}")->assertNoContent();

        $this->assertSame(['Ana Ruiz quitó Google Analytics 4 de Clínica Sonrisa'], $this->titlesFor($this->admin));
        $this->assertSame([], $this->titlesFor($this->owner));
        $this->assertSame('Ana Ruiz', $this->webhooks['source.removed'][0]['by'] ?? null);
    }

    public function test_each_person_chooses_the_events_the_email_and_the_sites(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);

        // Beto: only Tienda Sol, and no email when a source is removed.
        $this->putJson('/api/v1/user/notification-settings', [
            'events' => ['source_removed' => ['email' => false]],
            'sites' => [$this->otherSite->id],
        ])->assertOk()->assertJsonPath('sites', [$this->otherSite->id]);

        // Caro: everything by email too, every site.
        Sanctum::actingAs($this->collaborator);
        $this->putJson('/api/v1/user/notification-settings', [
            'events' => ['source_removed' => ['email' => true], 'source_connected' => ['app' => false]],
            'sites' => null,
        ])->assertOk();

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/data-sources/'.$this->source()->id)->assertNoContent();
        $this->deleteJson('/api/v1/data-sources/'.$this->source(site: $this->otherSite)->id)->assertNoContent();

        // Beto hears only about his site, in the bell, not by email.
        $this->assertSame(['Ana Ruiz quitó Google Analytics 4 de Tienda Sol'], $this->titlesFor($this->admin));
        Mail::assertNotSent(SourceActivityMail::class, fn (SourceActivityMail $mail): bool => $mail->hasTo('beto@norte.test'));
        // Caro hears about both, by email too.
        $this->assertCount(2, $this->titlesFor($this->collaborator));
        Mail::assertSent(SourceActivityMail::class, fn (SourceActivityMail $mail): bool => $mail->hasTo('caro@norte.test'));
    }

    public function test_the_settings_show_defaults_and_only_keep_this_accounts_sites(): void
    {
        $foreign = Site::factory()->create(['agency_id' => Agency::factory()->create()->id]);

        Sanctum::actingAs($this->collaborator);
        $this->getJson('/api/v1/user/notification-settings')
            ->assertOk()
            ->assertJsonPath('sites', null)
            ->assertJsonPath('events.0.key', 'source_connected')
            ->assertJsonPath('events.0.app', true)
            ->assertJsonPath('events.0.email', false);

        $this->putJson('/api/v1/user/notification-settings', ['sites' => [$this->site->id, $foreign->id]])
            ->assertOk()
            ->assertJsonPath('sites', [$this->site->id]);

        $this->putJson('/api/v1/user/notification-settings', ['events' => ['made_up' => ['app' => true]], 'sites' => null])->assertStatus(422);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/user/notification-settings')->assertJsonPath('events.1.key', 'source_failed')->assertJsonPath('events.1.email', true);
    }

    public function test_the_agencys_old_switch_still_turns_failure_emails_off_by_default(): void
    {
        Mail::fake();
        $this->agency->update(['settings' => ['notify' => ['source_failures' => false]]]);
        $source = $this->source(DataSourceStatus::Error);

        app()->call([new NotifySourceFailure($source->id), 'handle']);

        Mail::assertNothingSent();
        // The bell still says it.
        $this->assertCount(1, $this->titlesFor($this->owner));
    }

    public function test_the_bell_lists_only_your_notices_and_marks_them_read(): void
    {
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/data-sources/'.$this->source()->id)->assertNoContent();
        $this->deleteJson('/api/v1/data-sources/'.$this->source()->id)->assertNoContent();

        Sanctum::actingAs($this->admin);
        $list = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 2)->assertJsonCount(2, 'data');
        $first = $list->json('data.0.id');
        $this->assertSame('source_removed', $list->json('data.0.kind'));

        $this->postJson('/api/v1/notifications/read', ['ids' => [$first]])->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('unread', 0);

        // The owner did it: nothing in their bell. Another agency sees nothing either.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0)->assertJsonCount(0, 'data');
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/read', ['ids' => [$first]])->assertOk();
        $this->assertSame(1, AppNotification::query()->withoutGlobalScopes()->where('id', $first)->whereNotNull('read_at')->count());
    }
}
