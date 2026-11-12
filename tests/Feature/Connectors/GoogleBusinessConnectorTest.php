<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Connect\ConnectRegistry;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\DiscoveryFailed;
use App\Connectors\GoogleBusiness\GoogleBusinessConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Google Business Profile: views on Search and Maps, what customers did on the listing, the
 * searches that found it and its reviews — and plain words when Google hasn't granted access.
 */
class GoogleBusinessConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_oauth.client_id' => 'cid.apps.googleusercontent.com', 'services.google_oauth.client_secret' => 'secret']);
    }

    private function source(string $location = 'accounts/111/locations/222'): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::GoogleBusiness,
            'config' => ['location' => $location],
            'credentials' => ['oauth_refresh_token' => 'rt'],
        ]);
    }

    /**
     * @param  array<string, int>  $values  "Y-m-d" => count (zeros are left out, like Google does)
     * @return array<string, mixed>
     */
    private function series(string $metric, array $values): array
    {
        $points = [];
        foreach ($values as $date => $value) {
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            $points[] = ['date' => ['year' => $year, 'month' => $month, 'day' => $day], 'value' => (string) $value];
        }

        return ['dailyMetric' => $metric, 'timeSeries' => ['datedValues' => $points]];
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'businessprofileperformance.googleapis.com/v1/locations/222:fetchMultiDailyMetricsTimeSeries*' => Http::response([
                'multiDailyMetricTimeSeries' => [[
                    'dailyMetricTimeSeries' => [
                        $this->series('BUSINESS_IMPRESSIONS_MOBILE_SEARCH', ['2026-06-01' => 120, '2026-06-02' => 80]),
                        $this->series('BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', ['2026-06-01' => 30]),
                        $this->series('BUSINESS_IMPRESSIONS_MOBILE_MAPS', ['2026-06-02' => 50]),
                        $this->series('BUSINESS_IMPRESSIONS_DESKTOP_MAPS', []),
                        $this->series('CALL_CLICKS', ['2026-06-01' => 4, '2026-06-03' => 2]),
                        $this->series('BUSINESS_DIRECTION_REQUESTS', ['2026-06-02' => 7]),
                        $this->series('WEBSITE_CLICKS', ['2026-06-01' => 9]),
                        $this->series('MESSAGING_CLICKS', []),
                        $this->series('BUSINESS_BOOKINGS', []),
                    ],
                ]],
            ]),
            'businessprofileperformance.googleapis.com/v1/locations/222/searchkeywords/impressions/monthly*' => Http::response([
                'searchKeywordsCounts' => [
                    ['searchKeyword' => 'dentista cerca', 'insightsValue' => ['value' => '140']],
                    ['searchKeyword' => 'clinica dental bogota', 'insightsValue' => ['value' => '310']],
                    ['searchKeyword' => 'ortodoncia', 'insightsValue' => ['threshold' => '15']],
                ],
            ]),
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/reviews*' => Http::response([
                'averageRating' => 4.6,
                'totalReviewCount' => 87,
                'reviews' => [
                    ['reviewId' => 'r3', 'reviewer' => ['displayName' => 'Laura Gómez', 'isAnonymous' => false], 'starRating' => 'FIVE', 'comment' => 'Excelente atención', 'createTime' => '2026-06-03T10:00:00Z', 'updateTime' => '2026-06-03T12:00:00Z', 'reviewReply' => ['comment' => '¡Gracias!', 'updateTime' => '2026-06-03T12:00:00Z']],
                    ['reviewId' => 'r2', 'reviewer' => ['displayName' => 'X', 'isAnonymous' => true], 'starRating' => 'THREE', 'createTime' => '2026-06-02T10:00:00Z', 'updateTime' => '2026-06-02T10:00:00Z'],
                    ['reviewId' => 'r1', 'reviewer' => ['displayName' => 'Pedro'], 'starRating' => 'FOUR', 'comment' => 'Bien', 'createTime' => '2026-05-28T10:00:00Z', 'updateTime' => '2026-05-28T10:00:00Z'],
                ],
            ]),
        ]);
    }

    public function test_it_reads_views_actions_searches_and_reviews(): void
    {
        $this->fakeGoogle();

        $set = (new GoogleBusinessConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-03'), []);

        $this->assertTrue($set->isOk(), (string) $set->error);
        $this->assertSame(230, $set->get('google_business.views_search'));
        $this->assertSame(50, $set->get('google_business.views_maps'));
        $this->assertSame(280, $set->get('google_business.views_total'));
        $this->assertSame(6, $set->get('google_business.calls'));
        $this->assertSame(7, $set->get('google_business.directions'));
        $this->assertSame(9, $set->get('google_business.website_clicks'));
        $this->assertSame(0, $set->get('google_business.messages'));
        $this->assertSame(22, $set->get('google_business.actions_total'));

        // Every day of the range, zeros included.
        $this->assertSame([
            ['date' => '2026-06-01', 'value' => 150],
            ['date' => '2026-06-02', 'value' => 130],
            ['date' => '2026-06-03', 'value' => 0],
        ], $set->get('google_business.views_by_date'));
        $this->assertSame([
            ['label' => 'Clics a la web', 'value' => 9],
            ['label' => 'Cómo llegar', 'value' => 7],
            ['label' => 'Llamadas', 'value' => 6],
        ], $set->get('google_business.actions_breakdown'));

        // Biggest first; «fewer than 15» rows are left out.
        $this->assertSame([
            ['label' => 'clinica dental bogota', 'value' => 310],
            ['label' => 'dentista cerca', 'value' => 140],
        ], $set->get('google_business.search_keywords'));

        $this->assertSame(4.6, $set->get('google_business.rating'));
        $this->assertSame(87, $set->get('google_business.reviews_total'));
        $this->assertSame(2, $set->get('google_business.reviews_new'));
        $this->assertSame(1, $set->get('google_business.reviews_unanswered'));
        $this->assertSame([
            ['Fecha' => '03/06/2026', 'Autor' => 'Laura Gómez', 'Estrellas' => 5, 'Comentario' => 'Excelente atención', 'Respondida' => 'Sí'],
            ['Fecha' => '02/06/2026', 'Autor' => 'Anónimo', 'Estrellas' => 3, 'Comentario' => '(sin comentario)', 'Respondida' => 'No'],
        ], $set->get('google_business.recent_reviews'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':fetchMultiDailyMetricsTimeSeries?dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_SEARCH&dailyMetrics=')
            && str_contains($request->url(), 'dailyRange.start_date.year=2026&dailyRange.start_date.month=6&dailyRange.start_date.day=1')
            && $request->hasHeader('Authorization', 'Bearer at'));
    }

    public function test_access_not_yet_approved_by_google_is_said_plainly(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            '*' => Http::response(['error' => ['code' => 429, 'message' => "Quota exceeded for quota metric 'Requests' and limit 'Requests per minute'"]], 429),
        ]);

        $set = (new GoogleBusinessConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), ['google_business.calls']);

        $this->assertTrue($set->isFailed());
        $this->assertStringContainsString('Google todavía no da acceso a la API de Perfil de Empresa', (string) $set->error);
    }

    public function test_the_listings_of_the_account_are_offered_to_pick(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => [['name' => 'accounts/111', 'accountName' => 'Clínica Sonrisa']]]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/111/locations*' => Http::response(['locations' => [
                ['name' => 'locations/222', 'title' => 'Clínica Sonrisa', 'storefrontAddress' => ['locality' => 'Bogotá']],
                ['name' => 'locations/333', 'title' => 'Clínica Sonrisa Norte'],
            ]]),
        ]);

        $resources = (new GoogleBusinessConnector)->connectableResources($this->source(''));

        $this->assertNotNull($resources);
        $this->assertSame('location', $resources->field);
        $this->assertSame([
            ['value' => 'accounts/111/locations/222', 'label' => 'Clínica Sonrisa · Bogotá'],
            ['value' => 'accounts/111/locations/333', 'label' => 'Clínica Sonrisa Norte'],
        ], $resources->options);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'readMask=name%2Ctitle%2CstorefrontAddress'));
    }

    public function test_listing_fails_with_the_reason_when_the_apis_are_off(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            '*' => Http::response(['error' => ['code' => 403, 'message' => 'My Business Account Management API has not been used in project 123 before or it is disabled.']], 403),
        ]);

        $this->expectException(DiscoveryFailed::class);
        $this->expectExceptionMessage('no están activadas en el proyecto de Google de la plataforma');

        (new GoogleBusinessConnector)->connectableResources($this->source(''));
    }

    public function test_it_is_registered_with_a_one_click_google_connection(): void
    {
        $this->app->forgetInstance(ConnectRegistry::class);

        $this->assertTrue(app(ConnectorRegistry::class)->has(DataSourceType::GoogleBusiness->value));
        $this->assertNotNull(app(ConnectRegistry::class)->for(DataSourceType::GoogleBusiness->value));
    }
}
