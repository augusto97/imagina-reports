<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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

/**
 * The values behind the editor's filter pickers. Filtering used to be a free-text box, so a
 * typo produced an empty block with no explanation; these come from the same stored snapshot
 * the report resolves against, so what you can pick is exactly what the report can show.
 */
class DimensionValuesApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Site, 1: DataSource} */
    private function siteWithCampaigns(): array
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::FacebookAds,
        ]);

        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payload' => [
                'facebook_ads.campaigns' => [
                    ['campaign' => 'Rebajas', 'platform' => 'instagram', 'spend' => 30],
                    ['campaign' => 'Rebajas', 'platform' => 'facebook', 'spend' => 70],
                    ['campaign' => 'Navidad', 'platform' => 'facebook', 'spend' => 10],
                ],
            ],
        ]);

        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        return [$site, $source];
    }

    public function test_it_lists_the_distinct_values_of_a_dimension_in_snapshot_order(): void
    {
        [$site] = $this->siteWithCampaigns();

        $this->getJson("/api/v1/sites/{$site->id}/dimension-values?source=facebook_ads&metric=campaigns&dimension=campaign")
            ->assertOk()
            // De-duplicated, and in the snapshot's own order (sorted by weight), so the
            // values that matter come first.
            ->assertExactJson(['values' => ['Rebajas', 'Navidad']]);
    }

    public function test_it_lists_a_second_dimension_of_the_same_dataset(): void
    {
        [$site] = $this->siteWithCampaigns();

        $this->getJson("/api/v1/sites/{$site->id}/dimension-values?source=facebook_ads&metric=campaigns&dimension=platform")
            ->assertOk()
            ->assertExactJson(['values' => ['instagram', 'facebook']]);
    }

    public function test_an_unknown_metric_or_dimension_returns_an_empty_list_rather_than_an_error(): void
    {
        [$site] = $this->siteWithCampaigns();

        $this->getJson("/api/v1/sites/{$site->id}/dimension-values?source=facebook_ads&metric=nope&dimension=campaign")
            ->assertOk()->assertExactJson(['values' => []]);
        $this->getJson("/api/v1/sites/{$site->id}/dimension-values?source=facebook_ads&metric=campaigns&dimension=nope")
            ->assertOk()->assertExactJson(['values' => []]);
        // A source the site doesn't even have.
        $this->getJson("/api/v1/sites/{$site->id}/dimension-values?source=ga4&metric=geo&dimension=country")
            ->assertOk()->assertExactJson(['values' => []]);
    }

    public function test_it_is_tenant_scoped(): void
    {
        [$site] = $this->siteWithCampaigns();
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson("/api/v1/sites/{$site->id}/dimension-values?source=facebook_ads&metric=campaigns&dimension=campaign")
            ->assertNotFound();
    }

    public function test_it_validates_its_query(): void
    {
        [$site] = $this->siteWithCampaigns();

        $this->getJson("/api/v1/sites/{$site->id}/dimension-values")->assertStatus(422);
    }
}
