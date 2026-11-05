<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\AiClient;
use App\Enums\DataSourceType;
use App\Enums\ReportMessageKind;
use App\Enums\ReportVisibility;
use App\Enums\UserRole;
use App\Jobs\NotifyReportMessage;
use App\Mail\ClientQuestionMail;
use App\Mail\QuestionAnsweredMail;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportMessage;
use App\Models\User;
use App\Reports\ReportAssistant;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Etapa 4 «el cliente participa»: questions from the portal, the agency's answers, and
 * «Pregúntale a tu reporte».
 */
class ReportConversationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create(['features' => ['ai_builder' => true, 'white_label' => true, 'remove_branding' => false, 'custom_domain' => false]]);
        $this->agency = Agency::factory()->create(['plan_id' => $plan->id, 'name' => 'Agencia Norte']);
        config(['services.anthropic.key' => 'test-key']);
    }

    private function report(array $attributes = []): Report
    {
        return Report::factory()->create([
            'agency_id' => $this->agency->id,
            'resolved_blocks' => [
                'blocks' => [['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => ['label' => 'Visitas'], 'style' => []]],
                'data' => ['k1' => ['value' => 4820, 'previous' => 4600, 'change_percent' => 4.8]],
            ],
            ...$attributes,
        ]);
    }

    public function test_a_client_sends_a_question_from_the_portal_and_the_agency_hears_about_it(): void
    {
        Queue::fake();
        $report = $this->report();

        $this->postJson("/api/v1/public/reports/{$report->public_token}/messages", ['name' => 'Laura', 'email' => 'Laura@Cliente.com', 'body' => '¿Por qué subieron las visitas?'])
            ->assertCreated()
            ->assertJsonPath('message.author', 'Laura')
            ->assertJsonPath('message.kind', 'client')
            ->assertJsonMissingPath('message.author_email');

        $message = ReportMessage::query()->sole();
        $this->assertSame('laura@cliente.com', $message->author_email);
        $this->assertSame($this->agency->id, $message->agency_id);
        Queue::assertPushed(NotifyReportMessage::class, fn (NotifyReportMessage $job): bool => $job->messageId === $message->id);

        // The thread is readable from the portal — without anyone's email.
        $this->getJson("/api/v1/public/reports/{$report->public_token}/messages")
            ->assertOk()
            ->assertJsonPath('messages.0.body', '¿Por qué subieron las visitas?')
            ->assertJsonPath('ai_available', true)
            ->assertJsonMissingPath('messages.0.author_email');
    }

    public function test_the_notification_emails_the_agency_and_pings_slack(): void
    {
        Mail::fake();
        $this->agency->update(['settings' => ['contact_email' => 'hola@norte.test']]);
        $report = $this->report();
        $message = ReportMessage::query()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'kind' => ReportMessageKind::Client, 'author_name' => 'Laura', 'author_email' => 'laura@cliente.com', 'body' => '¿Qué es el rebote?']);

        $webhooks = new class implements WebhookDispatcher
        {
            /** @var list<string> */
            public array $events = [];

            public function dispatch(int $agencyId, string $event, array $payload): void
            {
                $this->events[] = $event;
            }
        };
        (new NotifyReportMessage($message->id))->handle($webhooks);

        Mail::assertSent(ClientQuestionMail::class, fn (ClientQuestionMail $mail): bool => $mail->hasTo('hola@norte.test') && $mail->hasReplyTo('laura@cliente.com')
            && str_contains($mail->render(), '¿Qué es el rebote?') && str_contains($mail->render(), 'tab=questions'));
        $this->assertSame(['report.question'], $webhooks->events);
    }

    public function test_without_a_contact_email_the_owners_and_admins_are_told(): void
    {
        Mail::fake();
        User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner, 'email' => 'owner@norte.test']);
        User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Collaborator, 'email' => 'colab@norte.test']);
        $report = $this->report();
        $message = ReportMessage::query()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'kind' => ReportMessageKind::Client, 'author_name' => 'Laura', 'body' => 'Hola']);

        (new NotifyReportMessage($message->id))->handle(app(WebhookDispatcher::class));

        Mail::assertSent(ClientQuestionMail::class, fn (ClientQuestionMail $mail): bool => $mail->hasTo('owner@norte.test') && ! $mail->hasTo('colab@norte.test'));
    }

    public function test_the_agency_answers_and_the_client_who_asked_gets_an_email(): void
    {
        Queue::fake();
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'name' => 'Ana']);
        Sanctum::actingAs($user);
        $report = $this->report();
        ReportMessage::query()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'kind' => ReportMessageKind::Client, 'author_name' => 'Laura', 'author_email' => 'laura@cliente.com', 'body' => '¿Qué es el rebote?']);

        $this->getJson('/api/v1/report-messages/inbox')->assertOk()->assertJsonPath('data.0.report_id', $report->id)->assertJsonPath('data.0.count', 1);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('0.pending_questions', 1);

        $this->postJson("/api/v1/reports/{$report->id}/messages", ['body' => 'Es la gente que entra y se va sin hacer nada.'])
            ->assertCreated()
            ->assertJsonPath('data.author', 'Ana')
            ->assertJsonPath('data.kind', 'agency');

        // Answering settles the pending question.
        $this->getJson('/api/v1/report-messages/inbox')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/reports/{$report->id}/messages")->assertOk()->assertJsonPath('data.0.author_email', 'laura@cliente.com');

        Mail::fake();
        $reply = ReportMessage::query()->where('kind', ReportMessageKind::Agency->value)->sole();
        (new NotifyReportMessage($reply->id))->handle(app(WebhookDispatcher::class));
        Mail::assertSent(QuestionAnsweredMail::class, fn (QuestionAnsweredMail $mail): bool => $mail->hasTo('laura@cliente.com')
            && str_contains($mail->render(), 'Es la gente que entra') && str_contains($mail->render(), 'Tu pregunta')
            && $mail->envelope()->subject === 'Agencia Norte respondió tu pregunta');
    }

    public function test_a_question_can_be_set_aside_without_answering(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
        $report = $this->report();
        ReportMessage::query()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'kind' => ReportMessageKind::Client, 'author_name' => 'Laura', 'body' => 'Gracias!']);

        $this->postJson("/api/v1/reports/{$report->id}/messages/handled")->assertNoContent();
        $this->assertNotNull(ReportMessage::query()->sole()->handled_at);
    }

    public function test_another_agency_cannot_read_or_answer_the_conversation(): void
    {
        $report = $this->report();
        ReportMessage::query()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'kind' => ReportMessageKind::Client, 'author_name' => 'Laura', 'body' => 'Privado']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson("/api/v1/reports/{$report->id}/messages")->assertNotFound();
        $this->postJson("/api/v1/reports/{$report->id}/messages", ['body' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/report-messages/inbox')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_conversation_obeys_the_report_sharing_rules(): void
    {
        $report = $this->report();
        $report->definition?->forceFill(['visibility' => ReportVisibility::Password, 'password_hash' => Hash::make('secreto')])->save();

        $this->getJson("/api/v1/public/reports/{$report->public_token}/messages")->assertStatus(401);
        $this->postJson("/api/v1/public/reports/{$report->public_token}/messages", ['name' => 'X', 'body' => 'Hola'])->assertStatus(401);
        $this->getJson("/api/v1/public/reports/{$report->public_token}/messages", ['X-Report-Password' => 'secreto'])->assertOk();
    }

    public function test_bots_filling_the_honeypot_are_ignored_and_validation_speaks_plainly(): void
    {
        Queue::fake();
        $report = $this->report();

        $this->postJson("/api/v1/public/reports/{$report->public_token}/messages", ['name' => 'Bot', 'body' => 'spam', 'website' => 'http://spam.test'])->assertCreated();
        $this->assertSame(0, ReportMessage::query()->count());
        Queue::assertNothingPushed();

        $this->postJson("/api/v1/public/reports/{$report->public_token}/messages", ['body' => 'Hola'])
            ->assertJsonValidationErrors(['name' => 'Dinos tu nombre para que la agencia sepa quién pregunta.']);
    }

    public function test_the_ai_answers_from_the_report_figures_and_the_agency_can_read_it(): void
    {
        $fake = new FakeAiClient('Este mes tuviste 4.820 visitas, un 4,8 % más que el anterior.');
        $this->app->instance(AiClient::class, $fake);
        $report = $this->report();

        $this->postJson("/api/v1/public/reports/{$report->public_token}/ask", ['question' => '¿Cuántas visitas tuve?', 'name' => 'Laura'])
            ->assertCreated()
            ->assertJsonPath('message.kind', 'ai')
            ->assertJsonPath('message.answer', 'Este mes tuviste 4.820 visitas, un 4,8 % más que el anterior.');

        // The report's own figures go to the AI.
        $this->assertStringContainsString('"Visitas":4820', (string) $fake->lastPrompt);
        $this->assertStringContainsString('¿Cuántas visitas tuve?', (string) $fake->lastPrompt);

        $message = ReportMessage::query()->sole();
        $this->assertSame(ReportMessageKind::Ai, $message->kind);
        $this->assertSame('Laura', $message->author_name);
    }

    public function test_the_ai_also_reads_everything_synced_for_the_period(): void
    {
        $report = $this->report(['period_start' => '2026-10-01 00:00:00', 'period_end' => '2026-10-31 23:59:59']);
        $site = $report->definition?->site;
        $this->assertNotNull($site);
        $source = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);
        $snapshot = static fn (string $start, string $end, array $metrics): MetricSnapshot => MetricSnapshot::factory()->create([
            'agency_id' => $source->agency_id, 'data_source_id' => $source->id, 'period_start' => $start, 'period_end' => $end,
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => $metrics],
        ]);
        // October: more than the report shows (countries, top pages…).
        $snapshot('2026-10-01 00:00:00', '2026-10-31 23:59:59', [
            'ga4.sessions' => 4820,
            'ga4.top_countries' => [['label' => 'Colombia', 'value' => 3100], ['label' => 'México', 'value' => 900]],
        ]);
        // September, for «vs. last month».
        $snapshot('2026-09-01 00:00:00', '2026-09-30 23:59:59', ['ga4.sessions' => 4600, 'ga4.top_countries' => [['label' => 'Colombia', 'value' => 2900]]]);

        $facts = app(ReportAssistant::class)->facts($report->fresh() ?? $report);

        $this->assertSame(
            [['label' => 'Colombia', 'value' => 3100], ['label' => 'México', 'value' => 900]],
            $facts['datos_sincronizados']['Google Analytics 4']['Países'] ?? null,
        );
        $this->assertSame(4820, $facts['datos_sincronizados']['Google Analytics 4']['Sesiones'] ?? null);
        // Only single figures from the previous period, for comparisons.
        $this->assertSame(['Sesiones' => 4600], $facts['periodo_anterior']['Google Analytics 4'] ?? null);
    }

    public function test_the_ai_is_off_without_the_plan_feature_or_when_the_agency_turns_it_off(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient('x'));
        $report = $this->report();

        $this->agency->update(['settings' => ['client_ai' => false]]);
        $this->postJson("/api/v1/public/reports/{$report->public_token}/ask", ['question' => '¿Cómo voy?'])->assertForbidden();
        $this->getJson("/api/v1/public/reports/{$report->public_token}/messages")->assertJsonPath('ai_available', false);

        $this->agency->update(['settings' => []]);
        $this->agency->plan?->update(['features' => ['ai_builder' => false]]);
        $this->postJson("/api/v1/public/reports/{$report->public_token}/ask", ['question' => '¿Cómo voy?'])->assertForbidden();
    }

    public function test_the_ai_has_a_daily_cap_per_report(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient('Respuesta'));
        $report = $this->report();
        Cache::put('report-assistant:'.$report->id.':'.now()->toDateString(), ReportAssistant::PER_REPORT_DAILY, now()->endOfDay());

        $this->postJson("/api/v1/public/reports/{$report->public_token}/ask", ['question' => '¿Cómo voy?'])->assertStatus(429);
        $this->assertSame(0, ReportMessage::query()->count());
    }

    public function test_the_agency_can_toggle_the_ai_for_its_clients(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner]));

        $this->getJson('/api/v1/agency')->assertOk()->assertJsonPath('client_ai', true);
        $this->putJson('/api/v1/agency', ['name' => 'Agencia Norte', 'client_ai' => false])->assertOk()->assertJsonPath('client_ai', false);
    }
}
