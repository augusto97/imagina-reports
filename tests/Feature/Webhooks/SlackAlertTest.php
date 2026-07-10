<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\SendSlackJob;
use App\Models\Agency;
use App\Services\Webhooks\HttpWebhookDispatcher;
use App\Services\Webhooks\SlackMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SlackAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_a_slack_message_for_a_noteworthy_event(): void
    {
        Queue::fake();
        $agency = Agency::factory()->create(['settings' => ['slack_webhook_url' => 'https://hooks.slack.com/services/x']]);

        app(HttpWebhookDispatcher::class)->dispatch($agency->id, 'anomaly.detected', [
            'report_id' => 7,
            'anomaly' => ['metric' => 'google_ads.cost', 'current' => 500, 'previous' => 200, 'change_percent' => 150],
        ]);

        Queue::assertPushed(SendSlackJob::class, fn (SendSlackJob $job): bool => $job->url === 'https://hooks.slack.com/services/x'
            && str_contains($job->text, 'google_ads.cost')
            && str_contains($job->text, 'reporte #7'));
    }

    public function test_it_does_not_queue_slack_without_a_configured_url(): void
    {
        Queue::fake();
        $agency = Agency::factory()->create(['settings' => []]);

        app(HttpWebhookDispatcher::class)->dispatch($agency->id, 'anomaly.detected', ['anomaly' => ['metric' => 'x']]);

        Queue::assertNotPushed(SendSlackJob::class);
    }

    public function test_it_ignores_noisy_events_for_slack(): void
    {
        $this->assertNull((new SlackMessage)->format('report.generated', ['report_id' => 1]));
        $this->assertNotNull((new SlackMessage)->format('report.sent', ['report_id' => 1]));
    }
}
