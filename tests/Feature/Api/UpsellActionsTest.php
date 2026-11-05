<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiClient;
use App\Enums\DataSourceType;
use App\Enums\UserRole;
use App\Enums\WorkLogStatus;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Etapa 5: opportunities with the agency's own thresholds, more signals, «ya la ofrecí» and a
 * ready-to-edit proposal email.
 */
class UpsellActionsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create(['features' => ['ai_builder' => false, 'white_label' => true, 'remove_branding' => false, 'custom_domain' => false]]);
        $this->agency = Agency::factory()->create(['plan_id' => $plan->id, 'name' => 'Agencia Norte']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Owner]));
        $client = Client::factory()->create(['agency_id' => $this->agency->id, 'name' => 'Clínica Sonrisa', 'contact_email' => 'laura@sonrisa.test']);
        $this->site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id, 'name' => 'sonrisa.com', 'plan_hours' => 10]);

        $ga4 = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id, 'type' => DataSourceType::Ga4]);
        foreach ([['2026-06-15', 1250], ['2026-05-15', 1000]] as [$day, $sessions]) {
            MetricSnapshot::factory()->create([
                'agency_id' => $this->agency->id,
                'data_source_id' => $ga4->id,
                'period_start' => $day.' 00:00:00',
                'period_end' => $day.' 23:59:59',
                'payload' => ['metrics' => ['ga4.sessions' => $sessions]],
                'captured_at' => $day,
            ]);
        }
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id]);
        Report::factory()->create(['agency_id' => $this->agency->id, 'report_definition_id' => $definition->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30']);
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        return array_column($this->getJson('/api/v1/upsell')->assertOk()->json('sites.0.opportunities') ?? [], 'type');
    }

    public function test_the_agency_sets_its_own_thresholds(): void
    {
        // +25 % visits: below the default 40 %, so no signal…
        $this->assertNotContains('traffic_growth', $this->types());

        // …until the agency says 20 % is already worth a call.
        $this->putJson('/api/v1/upsell/thresholds', ['traffic_growth_pct' => 20])->assertOk()->assertJsonPath('thresholds.traffic_growth_pct', 20);
        $this->assertContains('traffic_growth', $this->types());
        $this->getJson('/api/v1/upsell')->assertJsonPath('thresholds.traffic_growth_pct', 20);
    }

    public function test_more_hours_than_the_plan_is_an_opportunity(): void
    {
        WorkLog::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id, 'performed_at' => '2026-06-10', 'minutes' => 14 * 60, 'status' => WorkLogStatus::Done]);

        $opportunity = collect($this->getJson('/api/v1/upsell')->json('sites.0.opportunities'))->firstWhere('type', 'plan_overuse');

        $this->assertNotNull($opportunity);
        $this->assertEquals(40.0, $opportunity['context']['over_percent']);
    }

    public function test_an_offered_opportunity_is_hidden_for_a_while(): void
    {
        $this->putJson('/api/v1/upsell/thresholds', ['traffic_growth_pct' => 20]);

        $this->postJson('/api/v1/upsell/offered', ['site_id' => $this->site->id, 'type' => 'traffic_growth'])->assertOk();

        $this->assertNotContains('traffic_growth', $this->types());
        $this->getJson('/api/v1/upsell')->assertJsonPath('summary.offered_count', 1);
    }

    public function test_it_drafts_a_proposal_with_the_clients_numbers(): void
    {
        $this->putJson('/api/v1/upsell/thresholds', ['traffic_growth_pct' => 20]);

        $this->postJson('/api/v1/upsell/proposal', ['site_id' => $this->site->id, 'type' => 'traffic_growth'])
            ->assertOk()
            ->assertJsonPath('ai', false)
            ->assertJsonPath('to', 'laura@sonrisa.test')
            ->assertJsonPath('subject', 'sonrisa.com: tus visitas crecieron un 25 %')
            ->assertJson(fn ($json) => $json->where('body', fn (string $body): bool => str_contains($body, '1.000 → 1.250') && str_contains($body, 'Agencia Norte'))->etc());

        // A signal the site doesn't show can't be proposed.
        $this->postJson('/api/v1/upsell/proposal', ['site_id' => $this->site->id, 'type' => 'sales_growth'])->assertNotFound();
    }

    public function test_with_ai_the_proposal_is_rewritten_by_the_ai(): void
    {
        $this->agency->plan?->update(['features' => ['ai_builder' => true]]);
        $this->app->instance(AiClient::class, new FakeAiClient('{"subject": "¡Qué mes!", "body": "Hola, equipo de Clínica Sonrisa: tus visitas crecieron un 25 %."}'));
        $this->putJson('/api/v1/upsell/thresholds', ['traffic_growth_pct' => 20]);

        $this->postJson('/api/v1/upsell/proposal', ['site_id' => $this->site->id, 'type' => 'traffic_growth'])
            ->assertOk()
            ->assertJsonPath('ai', true)
            ->assertJsonPath('subject', '¡Qué mes!');
    }

    public function test_another_agency_cannot_use_these_actions(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/upsell/proposal', ['site_id' => $this->site->id, 'type' => 'traffic_growth'])->assertNotFound();
        $this->postJson('/api/v1/upsell/offered', ['site_id' => $this->site->id, 'type' => 'traffic_growth'])->assertNotFound();
    }
}
