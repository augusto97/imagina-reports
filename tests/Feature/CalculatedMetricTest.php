<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalculatedMetricTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function siteWithRevenue(): Site
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'type' => DataSourceType::WooCommerce]);

        MetricSnapshot::factory()->create([
            'agency_id' => $this->agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30 23:59:59',
            'payload' => ['status' => 'ok', 'error' => null, 'metrics' => ['woocommerce.revenue' => 1000, 'woocommerce.orders' => 8]],
        ]);

        return $site;
    }

    public function test_it_persists_agency_calculated_metrics(): void
    {
        $this->putJson('/api/v1/agency/calculated-metrics', [
            'calculated_metrics' => [
                ['key' => 'aov', 'label' => 'Ticket medio', 'formula' => 'woocommerce.revenue / woocommerce.orders'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('calculated_metrics.0.key', 'aov');

        $this->assertSame('aov', $this->agency->refresh()->calculated_metrics[0]['key']);
    }

    public function test_an_invalid_calculated_metric_is_rejected(): void
    {
        $this->putJson('/api/v1/agency/calculated-metrics', [
            'calculated_metrics' => [['key' => '1bad', 'formula' => 'x']],
        ])->assertStatus(422);
    }

    public function test_calc_preview_computes_a_formula_against_real_data(): void
    {
        $site = $this->siteWithRevenue();

        $response = $this->postJson("/api/v1/sites/{$site->id}/calc-preview", [
            'calculated_metrics' => [['key' => 'aov', 'label' => 'AOV', 'formula' => 'woocommerce.revenue / woocommerce.orders']],
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])->assertOk();

        $this->assertSame(125, $response->json('values')['calc.aov']);
    }

    public function test_agency_calc_metrics_appear_in_the_site_catalog(): void
    {
        $site = $this->siteWithRevenue();
        $this->agency->update(['calculated_metrics' => [['key' => 'aov', 'label' => 'Ticket medio', 'formula' => 'woocommerce.revenue / woocommerce.orders']]]);

        $catalog = $this->getJson("/api/v1/sites/{$site->id}/metric-catalog")->assertOk()->json();

        $this->assertContains('calc.aov', array_column($catalog, 'key'));
    }
}
