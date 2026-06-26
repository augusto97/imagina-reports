<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Ai\AiClient;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Enums\ReportStatus;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Reports\ReportGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class ReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        app(TenantContext::class)->set($this->agency->id);

        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $this->site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
    }

    private function dataSource(DataSourceType $type): DataSource
    {
        return DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function snapshot(DataSource $source, string $start, string $end, array $metrics): void
    {
        MetricSnapshot::factory()->create([
            'agency_id' => $this->agency->id,
            'data_source_id' => $source->id,
            'period_start' => $start,
            'period_end' => $end,
            'payload' => ['metrics' => $metrics],
            'captured_at' => $start,
        ]);
    }

    /**
     * @return list<string>
     */
    private function visibleIds(Report $report): array
    {
        $blocks = $report->resolved_blocks['blocks'] ?? [];

        return array_map(static fn (array $block): string => (string) $block['id'], is_array($blocks) ? $blocks : []);
    }

    public function test_page_filters_baked_in_the_definition_apply_when_generating(): void
    {
        $ga4 = $this->dataSource(DataSourceType::Ga4);
        $period = Period::make('2026-06-01', '2026-06-30');

        $this->snapshot($ga4, '2026-06-15 00:00:00', '2026-06-15 23:59:59', [
            'ga4.geo' => [
                ['country' => 'Colombia', 'city' => 'Bogotá', 'sessions' => 120],
                ['country' => 'Colombia', 'city' => 'Medellín', 'sessions' => 80],
                ['country' => 'México', 'city' => 'CDMX', 'sessions' => 200],
            ],
        ]);

        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'blocks' => [
                ['id' => 'geo', 'type' => 'table', 'binding' => ['source' => 'ga4', 'metric' => 'geo', 'measure' => 'sessions', 'breakdown' => 'city']],
            ],
            // Whole-dashboard filter: only Colombia. Block has no filter → page filter drives it.
            'filters' => ['all' => [['dimension' => 'country', 'op' => 'is', 'value' => 'Colombia']]],
        ]);

        $report = app(ReportGenerator::class)->generate($definition, $period);

        $rows = $report->resolved_blocks['data']['geo'] ?? null;
        $this->assertIsArray($rows);
        $labels = array_column($rows, 'label');
        $this->assertContains('Bogotá', $labels);
        $this->assertContains('Medellín', $labels);
        $this->assertNotContains('CDMX', $labels);
    }

    public function test_named_pages_are_frozen_into_the_report(): void
    {
        $period = Period::make('2026-06-01', '2026-06-30');

        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'blocks' => [
                ['id' => 'cv', 'type' => 'cover', 'page' => 0],
                ['id' => 'h', 'type' => 'header', 'page' => 1],
            ],
            'pages' => [['name' => 'Portada'], ['name' => 'Resumen']],
        ]);

        $report = app(ReportGenerator::class)->generate($definition, $period);

        $pages = $report->resolved_blocks['pages'] ?? null;
        $this->assertSame([['name' => 'Portada'], ['name' => 'Resumen']], $pages);
    }

    public function test_it_resolves_bound_blocks_and_hides_data_less_ones(): void
    {
        $ga4 = $this->dataSource(DataSourceType::Ga4);
        $mainwp = $this->dataSource(DataSourceType::MainWp);
        $period = Period::make('2026-06-01', '2026-06-30');

        $this->snapshot($ga4, '2026-06-15 00:00:00', '2026-06-15 23:59:59', [
            'ga4.sessions' => 1500,
            'ga4.sessions_by_date' => [['date' => '20260601', 'value' => 40]],
            'ga4.top_pages' => [['label' => '/', 'value' => 10]],
        ]);
        // Two MainWP snapshots → maintenance delta of 7 applied updates.
        $this->snapshot($mainwp, '2026-06-01 00:00:00', '2026-06-01 23:59:59', ['mainwp.updates_available' => 10, 'mainwp.ssl_expiring' => 0]);
        $this->snapshot($mainwp, '2026-06-28 00:00:00', '2026-06-28 23:59:59', ['mainwp.updates_available' => 3, 'mainwp.ssl_expiring' => 0]);

        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
        ]);

        $report = app(ReportGenerator::class)->generate($definition, $period);

        $data = $report->resolved_blocks['data'] ?? [];
        $this->assertIsArray($data);

        // Bound GA4 + computed MainWP delta resolved. The default template's KPIs
        // compare vs the previous period, so each resolves to a {value, previous,
        // change_percent} card (no prior snapshot here → previous is null).
        $this->assertSame(1500, $data['kpi_visits']['value']);
        $this->assertNull($data['kpi_visits']['previous']);
        $this->assertSame(7, $data['kpi_updates']['value']);

        // Health score: only the updates signal is present (3 pending → 85); security
        // (VirusDie) and the rest aren't connected, so it re-weights to 85.
        $this->assertSame(85, $report->health_score);
        $this->assertSame(85, $data['health']);

        $visible = $this->visibleIds($report);
        $this->assertContains('header', $visible);
        $this->assertContains('kpi_visits', $visible);
        $this->assertContains('traffic_chart', $visible);
        $this->assertContains('top_pages', $visible);

        // No Woo / Better Stack / CrowdSec / GSC data → those blocks are hidden (§10.4).
        $this->assertNotContains('kpi_sales', $visible);
        $this->assertNotContains('kpi_uptime', $visible);
        $this->assertNotContains('kpi_attacks', $visible);
        $this->assertNotContains('top_queries', $visible);

        $this->assertSame(ReportStatus::Draft, $report->status);
        $this->assertNotEmpty($report->public_token);
    }

    public function test_with_no_snapshots_data_blocks_hide_but_static_blocks_remain(): void
    {
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
        ]);

        $report = app(ReportGenerator::class)->generate($definition, Period::make('2026-06-01', '2026-06-30'));

        $visible = $this->visibleIds($report);
        $this->assertContains('header', $visible);
        $this->assertContains('worklog', $visible);
        $this->assertContains('footer', $visible);
        $this->assertNotContains('kpi_visits', $visible);
        $this->assertSame(100, $report->health_score);
    }

    public function test_it_matches_a_snapshot_synced_for_the_period_despite_time_of_day(): void
    {
        $ga4 = $this->dataSource(DataSourceType::Ga4);
        // Stored exactly like "Sincronizar ahora": full month with an end-of-month time.
        $this->snapshot($ga4, '2026-06-01 00:00:00', '2026-06-30 23:59:59', ['ga4.sessions' => 4321]);

        $template = ReportTemplate::factory()->create([
            'agency_id' => $this->agency->id,
            'blocks' => [['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => ['title' => 'Visitas']]],
        ]);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'template_id' => $template->id,
        ]);

        // Generate the SAME month with bare dates, as the generate form sends them.
        $report = app(ReportGenerator::class)->generate($definition, Period::make('2026-06-01', '2026-06-30'));

        $this->assertSame(4321, $report->resolved_blocks['data']['k1'] ?? null);
        $this->assertSame([], $report->resolved_blocks['diagnostics'] ?? null);
    }

    public function test_it_writes_the_ai_narrative_into_the_executive_summary_block(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient('Este mes tu sitio recibió 1.500 visitas y se mantuvo protegido.'));

        $ga4 = $this->dataSource(DataSourceType::Ga4);
        $this->snapshot($ga4, '2026-06-15 00:00:00', '2026-06-15 23:59:59', ['ga4.sessions' => 1500]);

        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'locale' => 'es',
        ]);

        $report = app(ReportGenerator::class)->generate($definition, Period::make('2026-06-01', '2026-06-30'));

        // Stored on the column AND injected into the default template's `summary` block so
        // the shared renderer shows it in the portal/PDF (data falls back to props.text).
        $this->assertSame('Este mes tu sitio recibió 1.500 visitas y se mantuvo protegido.', $report->executive_summary);
        $this->assertSame($report->executive_summary, $report->resolved_blocks['data']['summary'] ?? null);
        $this->assertContains('summary', $this->visibleIds($report));
    }

    public function test_generation_survives_a_failing_ai_narrative(): void
    {
        // An AI client that throws must not break GENERATE — the summary stays empty.
        $this->app->instance(AiClient::class, new class implements AiClient
        {
            public function complete(string $system, string $prompt): string
            {
                throw new RuntimeException('Claude API down');
            }
        });

        $ga4 = $this->dataSource(DataSourceType::Ga4);
        $this->snapshot($ga4, '2026-06-15 00:00:00', '2026-06-15 23:59:59', ['ga4.sessions' => 1500]);

        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
        ]);

        $report = app(ReportGenerator::class)->generate($definition, Period::make('2026-06-01', '2026-06-30'));

        $this->assertNull($report->executive_summary);
        $this->assertSame(1500, $report->resolved_blocks['data']['kpi_visits']['value'] ?? null);
        $this->assertArrayNotHasKey('summary', $report->resolved_blocks['data']);
    }

    public function test_it_records_diagnostics_when_a_bound_metric_has_no_data(): void
    {
        $this->dataSource(DataSourceType::Ga4); // source exists but no snapshot for the period

        $template = ReportTemplate::factory()->create([
            'agency_id' => $this->agency->id,
            'blocks' => [['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => []]],
        ]);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'template_id' => $template->id,
        ]);

        $report = app(ReportGenerator::class)->generate($definition, Period::make('2026-06-01', '2026-06-30'));

        $this->assertSame([], $report->resolved_blocks['blocks']); // the data-less block is hidden
        $diagnostics = $report->resolved_blocks['diagnostics'] ?? [];
        $this->assertCount(1, $diagnostics);
        $this->assertSame('ga4.sessions', $diagnostics[0]['source'].'.'.$diagnostics[0]['metric']);
    }
}
