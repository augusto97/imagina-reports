<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Enums\ReportMessageKind;
use App\Enums\ReportStatus;
use App\Enums\WorkLogStatus;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Etapa 5: which clients may be about to cancel, and why.
 */
class ChurnRiskTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<array{0: string, 1: bool, 2: int}>  $reports  [period_start, opened, health]
     */
    private function site(string $name, array $reports): Site
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id, 'name' => $name]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id]);
        foreach ($reports as [$start, $opened, $health]) {
            $from = Carbon::parse($start);
            Report::factory()->create([
                'agency_id' => $this->agency->id,
                'report_definition_id' => $definition->id,
                'period_start' => $from->toDateString(),
                'period_end' => $from->copy()->endOfMonth()->toDateString(),
                'status' => ReportStatus::Sent,
                'health_score' => $health,
                'first_viewed_at' => $opened ? $from->copy()->addMonth()->addDays(2) : null,
            ]);
        }

        return $site;
    }

    public function test_an_engaged_client_is_not_at_risk_and_a_silent_one_is(): void
    {
        $happy = $this->site('Feliz', [['2026-10-01', true, 92], ['2026-09-01', true, 90]]);
        WorkLog::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $happy->id, 'performed_at' => '2026-10-20', 'status' => WorkLogStatus::Done]);

        $silent = $this->site('Silencioso', [['2026-08-01', false, 70], ['2026-07-01', false, 85]]);
        DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $silent->id, 'type' => DataSourceType::Ga4, 'status' => DataSourceStatus::Error]);

        $response = $this->getJson('/api/v1/churn-risk')->assertOk();

        $this->assertSame(['Silencioso'], array_column($response->json('sites'), 'site_name'));
        $risk = $response->json('sites.0');
        $this->assertSame('high', $risk['level']);
        $keys = array_column($risk['reasons'], 'key');
        foreach (['unopened', 'no_report', 'health_drop', 'sources', 'no_work'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertSame('No abrió los dos últimos informes', $risk['reasons'][0]['text']);
        $response->assertJsonPath('summary.high', 1)->assertJsonPath('summary.sites', 2);
    }

    public function test_a_question_waiting_two_days_counts(): void
    {
        $site = $this->site('Con pregunta', [['2026-10-01', true, 90]]);
        WorkLog::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'performed_at' => '2026-10-20', 'status' => WorkLogStatus::Done]);
        $report = Report::query()->sole();
        $message = ReportMessage::query()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'kind' => ReportMessageKind::Client, 'author_name' => 'Laura', 'body' => '¿Hola?']);
        $message->forceFill(['created_at' => now()->subDays(3)])->save();

        $risk = $this->getJson('/api/v1/churn-risk')->assertOk()->json('sites.0');

        $this->assertSame(['question'], array_column($risk['reasons'], 'key'));
        $this->assertSame('Tiene una pregunta sin responder desde hace 3 días', $risk['reasons'][0]['text']);
        $this->assertSame('medium', $risk['level']);
    }

    public function test_it_only_reads_the_current_agency(): void
    {
        $this->site('Mío', [['2026-09-01', false, 50]]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson('/api/v1/churn-risk')->assertOk()->assertJsonCount(0, 'sites')->assertJsonPath('summary.sites', 0);
    }
}
