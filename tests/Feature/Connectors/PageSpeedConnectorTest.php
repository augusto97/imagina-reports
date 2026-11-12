<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\ConnectorRegistry;
use App\Connectors\PageSpeed\PageSpeedConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Services\Platform\OAuthCredentials;
use App\Services\SyncService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Site speed from Google PageSpeed Insights: the phone/computer score, the timings behind it,
 * what real visitors lived through (when Chrome has data), and what would make it faster.
 */
class PageSpeedConnectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A trimmed `runPagespeed` answer with the fields the connector reads.
     *
     * @return array<string, mixed>
     */
    private function response(float $score, bool $withField = true): array
    {
        return [
            'lighthouseResult' => [
                'categories' => ['performance' => ['id' => 'performance', 'score' => $score]],
                'audits' => [
                    'largest-contentful-paint' => ['id' => 'largest-contentful-paint', 'score' => 0.4, 'numericValue' => 3480.2],
                    'first-contentful-paint' => ['id' => 'first-contentful-paint', 'score' => 0.8, 'numericValue' => 1612.7],
                    'total-blocking-time' => ['id' => 'total-blocking-time', 'score' => 0.6, 'numericValue' => 310.4],
                    'cumulative-layout-shift' => ['id' => 'cumulative-layout-shift', 'score' => 0.95, 'numericValue' => 0.0412],
                    'render-blocking-resources' => ['id' => 'render-blocking-resources', 'title' => 'Elimina los recursos que bloquean el renderizado', 'score' => 0.3, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 1250]],
                    'uses-optimized-images' => ['id' => 'uses-optimized-images', 'title' => 'Codifica las imágenes de forma eficaz', 'score' => 0.5, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 450]],
                    'unused-css-rules' => ['id' => 'unused-css-rules', 'title' => 'Reduce el CSS que no se usa', 'score' => 0.95, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 900]],
                ],
            ],
            'loadingExperience' => $withField ? [
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2353, 'category' => 'FAST'],
                    'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 240, 'category' => 'AVERAGE'],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 5, 'category' => 'FAST'],
                    'FIRST_CONTENTFUL_PAINT_MS' => ['percentile' => 1490, 'category' => 'FAST'],
                ],
                'overall_category' => 'AVERAGE',
            ] : ['id' => 'https://clinica.test/'],
        ];
    }

    private function source(array $config = []): DataSource
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'url' => 'https://clinica.test']);

        return DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::PageSpeed, 'config' => $config, 'credentials' => []]);
    }

    public function test_it_reads_the_score_timings_real_visitors_and_improvements(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->response(str_contains($request->url(), 'strategy=desktop') ? 0.91 : 0.58)));

        $set = (new PageSpeedConnector)->fetch($this->source(), Period::make('first day of this month', 'last day of this month'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(58, $set->get('pagespeed.score_mobile'));
        $this->assertSame(91, $set->get('pagespeed.score_desktop'));
        $this->assertSame(3.5, $set->get('pagespeed.lcp_mobile'));
        $this->assertSame(1.6, $set->get('pagespeed.fcp_mobile'));
        $this->assertSame(310, $set->get('pagespeed.tbt_mobile'));
        $this->assertSame(0.041, $set->get('pagespeed.cls_mobile'));
        // Real visitors: LCP in seconds, INP in ms, CLS reported ×100 by the API.
        $this->assertSame(2.4, $set->get('pagespeed.field_lcp'));
        $this->assertSame(240, $set->get('pagespeed.field_inp'));
        $this->assertSame(0.05, $set->get('pagespeed.field_cls'));

        $this->assertSame([
            ['Indicador' => 'Carga del contenido principal (LCP)', 'Resultado' => '2,4 s', 'Estado' => 'Bueno', 'Medido con' => 'Visitantes reales (28 días)'],
            ['Indicador' => 'Respuesta al tocar (INP)', 'Resultado' => '240 ms', 'Estado' => 'Mejorable', 'Medido con' => 'Visitantes reales (28 días)'],
            ['Indicador' => 'Estabilidad visual (CLS)', 'Resultado' => '0,05', 'Estado' => 'Bueno', 'Medido con' => 'Visitantes reales (28 días)'],
            ['Indicador' => 'Primer contenido visible (FCP)', 'Resultado' => '1,5 s', 'Estado' => 'Bueno', 'Medido con' => 'Visitantes reales (28 días)'],
        ], $set->get('pagespeed.vitals'));

        // Biggest saving first; audits already passing (score ≥ 0.9) are left out.
        $this->assertSame([
            ['Mejora' => 'Elimina los recursos que bloquean el renderizado', 'Ahorro estimado' => '1,3 s'],
            ['Mejora' => 'Codifica las imágenes de forma eficaz', 'Ahorro estimado' => '0,5 s'],
        ], $set->get('pagespeed.opportunities'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'url=https%3A%2F%2Fclinica.test')
            && str_contains($request->url(), 'strategy=mobile')
            && str_contains($request->url(), 'locale=es'));
    }

    public function test_without_real_visitor_data_the_summary_uses_googles_test(): void
    {
        Http::fake(['*' => Http::response($this->response(0.42, false))]);

        $set = (new PageSpeedConnector)->fetch($this->source(['url' => 'clinica.test/servicios']), Period::make('first day of this month', 'last day of this month'), ['pagespeed.score_mobile', 'pagespeed.vitals']);

        $this->assertSame(42, $set->get('pagespeed.score_mobile'));
        $this->assertFalse($set->has('pagespeed.field_lcp'));
        $this->assertFalse($set->has('pagespeed.score_desktop'));
        $vitals = $set->get('pagespeed.vitals');
        $this->assertIsArray($vitals);
        $this->assertSame(['Carga del contenido principal (LCP)', '3,5 s', 'Mejorable', 'Prueba de Google (celular)'], array_values($vitals[0]));
        $this->assertSame('Tiempo sin responder al cargar (TBT)', $vitals[1]['Indicador']);
        // Only the phone run was needed, against the configured page.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'url=https%3A%2F%2Fclinica.test%2Fservicios'));
    }

    public function test_the_platform_key_is_sent_and_errors_read_clearly(): void
    {
        $settings = PlatformSetting::current();
        $settings->putSecret(OAuthCredentials::GOOGLE_PAGESPEED_KEY, 'psi-key');
        $settings->save();
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded']], 429)]);

        $set = (new PageSpeedConnector)->fetch($this->source(), Period::make('first day of this month', 'last day of this month'), ['pagespeed.score_mobile']);

        $this->assertTrue($set->isFailed());
        $this->assertStringContainsString('Google limitó las pruebas de velocidad', (string) $set->error);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=psi-key'));
    }

    public function test_without_a_platform_key_the_error_says_who_must_add_it(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'message' => "Quota exceeded for quota metric 'Queries' and limit 'Queries per day'"]], 429)]);

        $result = (new PageSpeedConnector)->testConnection($this->source());

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('todavía no tiene su propia clave de PageSpeed', $result->message);
    }

    public function test_a_month_long_gone_is_not_measured_with_todays_speed(): void
    {
        Http::fake(['*' => Http::response($this->response(0.8))]);
        $source = $this->source();
        app(TenantContext::class)->set($source->agency_id);

        $this->assertNull(app(SyncService::class)->sync($source, Period::make(now()->subMonths(3)->startOfMonth(), now()->subMonths(3)->endOfMonth())));
        Http::assertNothingSent();
        $this->assertSame(0, MetricSnapshot::query()->count());

        // The month that just ended is still measured (a report built in its first days needs it).
        app(SyncService::class)->sync($source, Period::make(now()->subDays(3)->startOfMonth(), now()->subDays(3)));
        $this->assertSame(1, MetricSnapshot::query()->count());
        $this->assertNotNull(app(ConnectorRegistry::class)->for($source));
    }
}
