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
