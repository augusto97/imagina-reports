<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Jobs\SendWebhookJob;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Reports\ReportGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReportWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Site $site;

    /**
     * @param  array<string, mixed>  $settings
     */
    private function bootAgency(array $settings): void
    {
        $this->agency = Agency::factory()->create(['settings' => $settings]);
        app(TenantContext::class)->set($this->agency->id);

        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $this->site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
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

    private function generateWithTrafficDrop(): void
    {
        $ga4 = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
            'type' => DataSourceType::Ga4,
        ]);

        // June (current) far below May (previous): a traffic drop anomaly.
        $this->snapshot($ga4, '2026-06-15 00:00:00', '2026-06-15 23:59:59', ['ga4.sessions' => 300]);
        $this->snapshot($ga4, '2026-05-15 00:00:00', '2026-05-15 23:59:59', ['ga4.sessions' => 1000]);

        $definition = ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $this->site->id,
        ]);

        app(ReportGenerator::class)->generate($definition, Period::make('2026-06-01', '2026-06-30'));
    }

    public function test_generation_emits_report_generated_and_anomaly_webhooks(): void
    {
        Queue::fake();
        $this->bootAgency(['webhook_urls' => ['https://hook.test/in'], 'webhook_secret' => 'shh']);

        $this->generateWithTrafficDrop();

        Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->event === 'report.generated' && $job->url === 'https://hook.test/in' && $job->secret === 'shh');
        Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->event === 'anomaly.detected');
    }

    public function test_no_webhooks_are_queued_when_the_agency_has_no_endpoints(): void
    {
        Queue::fake();
        $this->bootAgency([]);

        $this->generateWithTrafficDrop();

        Queue::assertNotPushed(SendWebhookJob::class);
    }
}
