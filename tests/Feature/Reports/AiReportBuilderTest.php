<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Ai\AiClient;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Reports\AiPlanLayout;
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

            public function complete(string $system, string $prompt, int $maxTokens = 16000): string
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

    public function test_adjusting_keeps_what_the_ai_left_alone_and_rebuilds_what_it_changed(): void
    {
        $site = $this->siteWithGa4();
        $current = [
            ['id' => 'hdr', 'type' => 'header', 'binding' => null, 'props' => ['title' => 'Tráfico', 'eyebrow' => 'Mi agencia'], 'style' => [], 'layout' => ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 3], 'page' => 0],
            // A KPI with manual settings (accent, format) the AI must not lose.
            ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions', 'compare' => 'prev_period'], 'props' => ['label' => 'Visitas'], 'style' => ['accent' => '#ff0000'], 'layout' => ['x' => 0, 'y' => 3, 'w' => 4, 'h' => 4], 'page' => 0],
            ['id' => 'c1', 'type' => 'chart', 'binding' => ['source' => 'ga4', 'metric' => 'sessions_by_date'], 'props' => ['chartType' => 'line', 'title' => 'Visitas por día'], 'style' => [], 'layout' => ['x' => 4, 'y' => 3, 'w' => 8, 'h' => 7], 'page' => 0],
            ['id' => 't1', 'type' => 'table', 'binding' => ['source' => 'ga4', 'metric' => 'top_pages'], 'props' => ['title' => 'Páginas'], 'style' => [], 'layout' => ['x' => 0, 'y' => 10, 'w' => 12, 'h' => 7], 'page' => 0],
            ['id' => 'img', 'type' => 'image', 'binding' => null, 'props' => ['url' => 'https://x.test/logo.png'], 'style' => [], 'layout' => ['x' => 0, 'y' => 17, 'w' => 6, 'h' => 4], 'page' => 0],
        ];

        $plan = AiPlanLayout::toPlan($current, ['Tráfico']);
        $this->assertSame('hdr', $plan['pages'][0]['header_ref']);
        $this->assertSame(['k1', 'c1', 't1', 'img'], array_column($plan['pages'][0]['items'], 'ref'));
        $this->assertSame('keep', $plan['pages'][0]['items'][3]['kind']);

        // The AI: retitles the KPI, turns the line into bars, drops the table, adds a donut, keeps the image.
        $revised = (string) json_encode(['title' => 'Informe', 'pages' => [[
            'name' => 'Tráfico y fuentes', 'header_ref' => 'hdr', 'items' => [
                ['ref' => 'k1', 'kind' => 'kpi', 'metric' => 'ga4.sessions', 'title' => 'Visitas del mes'],
                ['ref' => 'c1', 'kind' => 'chart', 'metric' => 'ga4.sessions_by_date', 'chart' => 'bar'],
                ['kind' => 'chart', 'metric' => 'ga4.traffic_sources', 'chart' => 'donut', 'title' => 'Fuentes'],
                ['ref' => 'img', 'kind' => 'keep'],
            ],
        ]]]);

        $result = $this->builderReturning($revised)->adjustTemplate($site, 'barras y quita la tabla', $current, ['Tráfico']);

        $byId = [];
        foreach ($result['blocks'] as $block) {
            $byId[$block['id']] = $block;
        }
        $this->assertSame(['Tráfico y fuentes'], $result['pages']);
        $this->assertArrayNotHasKey('t1', $byId, 'the table was removed');
        $this->assertSame('Visitas del mes', $byId['k1']['props']['label']);
        $this->assertSame(['accent' => '#ff0000'], $byId['k1']['style'], 'manual settings survive');
        $this->assertSame('bar', $byId['c1']['props']['chartType']);
        $this->assertSame('https://x.test/logo.png', $byId['img']['props']['url']);
        $this->assertSame('Mi agencia', $byId['hdr']['props']['eyebrow']);
        $this->assertSame('Tráfico y fuentes', $byId['hdr']['props']['title']);
        $new = array_values(array_filter($result['blocks'], static fn (array $block): bool => ! in_array($block['id'], ['hdr', 'k1', 'c1', 'img'], true)));
        $this->assertCount(1, $new);
        $this->assertSame('donut', $new[0]['props']['chartType']);
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
