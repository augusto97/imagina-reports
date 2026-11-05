<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\SiteGoal;
use App\Models\User;
use App\Reports\ReportGenerator;
use App\Reports\SiteGoals;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Etapa 4: each client's own goals, measured every period and shown by the «Metas» block.
 */
class SiteGoalsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $this->site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
    }

    public function test_the_agency_manages_a_sites_goals(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));

        $id = $this->postJson("/api/v1/sites/{$this->site->id}/goals", ['label' => 'Visitas del mes', 'source' => 'ga4', 'metric' => 'sessions', 'target' => 5000])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'at_least')
            ->assertJsonPath('data.target', 5000)
            ->json('data.id');

        $this->putJson("/api/v1/site-goals/{$id}", ['target' => 6000, 'direction' => 'at_most'])->assertOk()->assertJsonPath('data.target', 6000)->assertJsonPath('data.direction', 'at_most');
        $this->getJson("/api/v1/sites/{$this->site->id}/goals")->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/sites/{$this->site->id}/goals", ['label' => '', 'source' => 'ga4', 'metric' => 'sessions', 'target' => 'mucho'])
            ->assertJsonValidationErrors(['label', 'target']);

        $this->deleteJson("/api/v1/site-goals/{$id}")->assertNoContent();
        $this->assertSame(0, SiteGoal::query()->count());
    }

    public function test_another_agency_cannot_touch_the_goals(): void
    {
        $goal = SiteGoal::query()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id, 'label' => 'Visitas', 'source' => 'ga4', 'metric' => 'sessions', 'target' => 5000]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson("/api/v1/sites/{$this->site->id}/goals")->assertNotFound();
        $this->putJson("/api/v1/site-goals/{$goal->id}", ['target' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/site-goals/{$goal->id}")->assertNotFound();
    }

    public function test_goals_are_measured_both_ways(): void
    {
        $make = fn (string $metric, float $target, string $direction): SiteGoal => new SiteGoal(['label' => $metric, 'source' => 'ga4', 'metric' => $metric, 'target' => $target, 'direction' => $direction]);
        $bags = ['ga4' => ['ga4.sessions' => 4820, 'ga4.load_time' => ['value' => 1.8], 'ga4.bounce' => 70]];

        $goals = SiteGoals::measure([
            $make('sessions', 5000, SiteGoal::AT_LEAST),
            $make('load_time', 2, SiteGoal::AT_MOST),
            $make('bounce', 60, SiteGoal::AT_MOST),
            $make('missing', 10, SiteGoal::AT_LEAST),
        ], $bags);

        $this->assertCount(3, $goals); // no data → left out
        $this->assertSame(96.4, $goals[0]['progress']);
        $this->assertFalse($goals[0]['met']);
        $this->assertTrue($goals[1]['met']);
        $this->assertFalse($goals[2]['met']);
    }

    public function test_the_goals_block_shows_this_sites_goals_and_hides_without_them(): void
    {
        app(TenantContext::class)->set($this->agency->id);
        $ga4 = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id, 'type' => DataSourceType::Ga4]);
        MetricSnapshot::factory()->create([
            'agency_id' => $this->agency->id,
            'data_source_id' => $ga4->id,
            'period_start' => '2026-06-15 00:00:00',
            'period_end' => '2026-06-15 23:59:59',
            'payload' => ['metrics' => ['ga4.sessions' => 5200]],
            'captured_at' => '2026-06-15 00:00:00',
        ]);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'blocks' => [['id' => 'h', 'type' => 'header'], ['id' => 'g', 'type' => 'goals']],
        ]);
        $period = Period::make('2026-06-01', '2026-06-30');

        $without = app(ReportGenerator::class)->generate($definition, $period);
        $this->assertSame(['h'], array_column($without->resolved_blocks['blocks'], 'id'));

        SiteGoal::query()->create(['agency_id' => $this->agency->id, 'site_id' => $this->site->id, 'label' => 'Visitas del mes', 'source' => 'ga4', 'metric' => 'sessions', 'target' => 5000]);
        $with = app(ReportGenerator::class)->generate($definition, $period);

        $this->assertSame(['h', 'g'], array_column($with->resolved_blocks['blocks'], 'id'));
        $this->assertSame('Visitas del mes', $with->resolved_blocks['data']['g'][0]['label']);
        $this->assertTrue($with->resolved_blocks['data']['g'][0]['met']);
    }
}
