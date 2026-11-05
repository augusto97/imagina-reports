<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Ai\AiClient;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Reports\AiReportBuilder;
use App\Reports\AiReportException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class AiReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithGa4(): Site
    {
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);

        return $site;
    }

    private function builderReturning(string $response): AiReportBuilder
    {
        $this->app->instance(AiClient::class, new FakeAiClient($response));

        return app(AiReportBuilder::class);
    }

    public function test_it_validates_against_the_catalog_and_drops_invented_bindings(): void
    {
        $site = $this->siteWithGa4();
        $response = json_encode([
            'blocks' => [
                ['id' => 'h', 'type' => 'header', 'binding' => null, 'props' => [], 'style' => []],
                ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => [], 'style' => []],
                ['id' => 'k2', 'type' => 'kpi', 'binding' => ['source' => 'woocommerce', 'metric' => 'revenue'], 'props' => [], 'style' => []],
            ],
            'narrative' => 'Buen mes.',
        ]);

        $result = $this->builderReturning((string) $response)->assembleTemplate($site);

        $ids = array_map(static fn (array $block): mixed => $block['id'], $result['blocks']);
        $this->assertSame(['h', 'k1'], $ids); // the invented woocommerce binding (k2) is dropped
        $this->assertSame('Buen mes.', $result['narrative']);

        // The dropped block is reported back so the editor can tell the user what was left out.
        $this->assertSame([['type' => 'kpi', 'metric' => 'woocommerce.revenue']], $result['dropped']);
    }

    public function test_assemble_section_returns_only_section_blocks_and_strips_structural_ones(): void
    {
        $site = $this->siteWithGa4();
        $response = (string) json_encode([
            'blocks' => [
                // A header/cover would belong to the whole report, not an inserted section — stripped.
                ['id' => 'h', 'type' => 'header', 'binding' => null, 'props' => [], 'style' => []],
                ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => [], 'style' => []],
                // Invented binding is still dropped and reported.
                ['id' => 'k2', 'type' => 'kpi', 'binding' => ['source' => 'woocommerce', 'metric' => 'revenue'], 'props' => [], 'style' => []],
            ],
        ]);

        $result = $this->builderReturning($response)->assembleSection($site, 'una sección de tráfico');

        $ids = array_map(static fn (array $block): mixed => $block['id'], $result['blocks']);
        $this->assertSame(['k1'], $ids);
        $this->assertArrayNotHasKey('narrative', $result);
        $this->assertSame([['type' => 'kpi', 'metric' => 'woocommerce.revenue']], $result['dropped']);
    }

    public function test_it_turns_a_multi_page_plan_into_a_tidy_layout(): void
    {
        $site = $this->siteWithGa4();
        $response = (string) json_encode([
            'title' => 'Informe 360',
            'pages' => [
                ['name' => 'Portada', 'type' => 'cover'],
                ['name' => 'Resumen', 'items' => [
                    ['kind' => 'healthscore'],
                    ['kind' => 'summary'],
                    ['kind' => 'kpi', 'metric' => 'ga4.sessions', 'title' => 'Visitas'],
                    // A KPI of a time series becomes a chart; a map of countries stays a map.
                    ['kind' => 'kpi', 'metric' => 'ga4.sessions_by_date', 'title' => 'Visitas por día'],
                    ['kind' => 'map', 'metric' => 'ga4.top_countries', 'title' => 'De dónde te visitan'],
                    // Invented metric: dropped and reported.
                    ['kind' => 'kpi', 'metric' => 'woocommerce.revenue', 'title' => 'Ventas'],
                ]],
                // A page whose items are all unusable is left out.
                ['name' => 'Ventas', 'items' => [['kind' => 'kpi', 'metric' => 'woocommerce.orders']]],
                ['name' => 'Cierre', 'type' => 'back_cover'],
            ],
        ]);

        $result = $this->builderReturning($response)->assembleTemplate($site, 'un informe completo');

        $this->assertSame(['Portada', 'Resumen', 'Cierre'], $result['pages']);
        $types = array_map(static fn (array $block): string => $block['type'].'@'.$block['page'], $result['blocks']);
        $this->assertSame(['cover@0', 'header@1', 'healthscore@1', 'narrative@1', 'kpi@1', 'chart@1', 'geo_map@1', 'back_cover@2'], $types);
        $this->assertSame([['type' => 'kpi', 'metric' => 'woocommerce.revenue'], ['type' => 'kpi', 'metric' => 'woocommerce.orders']], $result['dropped']);

        $byType = [];
        foreach ($result['blocks'] as $block) {
            $byType[$block['type']] = $block;
        }
        $this->assertSame('Informe 360', $byType['cover']['props']['title']);
        $this->assertSame('Resumen', $byType['header']['props']['title']);
        $this->assertSame(['source' => 'ga4', 'metric' => 'sessions', 'compare' => 'prev_period'], $byType['kpi']['binding']);
        $this->assertSame('area', $byType['chart']['props']['chartType']);
        // Health gauge + summary share the first row; every row ends flush on the 12-column grid.
        $this->assertSame(['x' => 0, 'y' => 3, 'w' => 4, 'h' => 7], $byType['healthscore']['layout']);
        $this->assertSame(['x' => 4, 'y' => 3, 'w' => 8, 'h' => 7], $byType['narrative']['layout']);
        $rows = [];
        foreach ($result['blocks'] as $block) {
            if ($block['page'] === 1 && $block['type'] !== 'header') {
                $rows[$block['layout']['y']] = ($rows[$block['layout']['y']] ?? 0) + $block['layout']['w'];
            }
        }
        $this->assertSame([12], array_values(array_unique($rows)));
    }

    public function test_a_section_plan_has_no_header_and_starts_at_the_top(): void
    {
        $site = $this->siteWithGa4();
        $response = (string) json_encode(['items' => [
            ['kind' => 'kpi', 'metric' => 'ga4.sessions'],
            ['kind' => 'chart', 'metric' => 'ga4.traffic_sources', 'chart' => 'donut', 'title' => 'Fuentes'],
        ]]);

        $result = $this->builderReturning($response)->assembleSection($site, 'tráfico');

        $this->assertSame(['kpi', 'chart'], array_column($result['blocks'], 'type'));
        $this->assertSame(0, $result['blocks'][0]['layout']['y']);
        $this->assertSame('donut', $result['blocks'][1]['props']['chartType']);
        $this->assertSame([], $result['dropped']);
    }

    public function test_it_retries_once_when_the_answer_is_not_json(): void
    {
        $site = $this->siteWithGa4();
        $answers = ['Claro, aquí tienes el informe…', (string) json_encode(['pages' => [['name' => 'Resumen', 'items' => [['kind' => 'kpi', 'metric' => 'ga4.sessions']]]]])];
        $this->app->instance(AiClient::class, new class($answers) implements AiClient
        {
            public int $calls = 0;

            /** @param list<string> $answers */
            public function __construct(private array $answers) {}

            public function complete(string $system, string $prompt): string
            {
                return $this->answers[$this->calls++] ?? '';
            }
        });

        $result = app(AiReportBuilder::class)->assembleTemplate($site);

        $this->assertSame(['header', 'kpi'], array_column($result['blocks'], 'type'));
    }

    public function test_a_plan_with_only_invented_metrics_says_so(): void
    {
        $site = $this->siteWithGa4();
        $response = (string) json_encode(['pages' => [['name' => 'Ventas', 'items' => [['kind' => 'kpi', 'metric' => 'woocommerce.revenue']]]]]);

        $this->expectException(AiReportException::class);
        $this->expectExceptionMessage('métricas que este sitio no tiene');
        $this->builderReturning($response)->assembleTemplate($site);
    }

    public function test_the_prompt_lists_the_catalog_and_the_request(): void
    {
        $site = $this->siteWithGa4();
        $fake = new FakeAiClient((string) json_encode(['pages' => [['name' => 'R', 'items' => [['kind' => 'kpi', 'metric' => 'ga4.sessions']]]]]));
        $this->app->instance(AiClient::class, $fake);

        app(AiReportBuilder::class)->assembleTemplate($site, 'hazlo multipágina');

        $this->assertStringContainsString('- ga4.sessions | Sesiones | scalar, count', (string) $fake->lastPrompt);
        $this->assertStringContainsString('hazlo multipágina', (string) $fake->lastPrompt);
    }

    public function test_it_throws_on_unparseable_output(): void
    {
        $site = $this->siteWithGa4();

        $this->expectException(AiReportException::class);
        $this->builderReturning('lo siento, no puedo')->assembleTemplate($site);
    }

    public function test_it_throws_on_an_invalid_block_layout(): void
    {
        $site = $this->siteWithGa4();
        $response = (string) json_encode(['blocks' => [['id' => 'x']]]); // missing type

        $this->expectException(AiReportException::class);
        $this->builderReturning($response)->assembleTemplate($site);
    }
}
