<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteWorkLogApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function site(): Site
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);

        return Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
    }

    public function test_it_quick_adds_a_work_log_with_time_and_category(): void
    {
        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/work-logs", [
            'description' => 'Actualicé plugins y core',
            'minutes' => 45,
            'category' => 'Mantenimiento',
        ])
            ->assertCreated()
            ->assertJsonPath('minutes', 45)
            ->assertJsonPath('category', 'Mantenimiento')
            ->assertJsonPath('site_id', $site->id);

        $this->assertDatabaseHas('ir_report_work_logs', ['site_id' => $site->id, 'minutes' => 45, 'report_id' => null]);
    }

    public function test_time_is_optional(): void
    {
        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/work-logs", ['description' => 'Revisé un correo del cliente'])
            ->assertCreated()
            ->assertJsonPath('minutes', null);
    }

    public function test_it_lists_logs_filtered_by_period(): void
    {
        $site = $this->site();
        WorkLog::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'performed_at' => '2026-06-10']);
        WorkLog::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'performed_at' => '2026-05-10']);

        $this->getJson("/api/v1/sites/{$site->id}/work-logs?from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_it_deletes_a_work_log(): void
    {
        $site = $this->site();
        $log = WorkLog::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id]);

        $this->deleteJson("/api/v1/work-logs/{$log->id}")->assertNoContent();
        $this->assertDatabaseMissing('ir_report_work_logs', ['id' => $log->id]);
    }

    public function test_it_cannot_add_to_another_agencys_site(): void
    {
        $other = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $other->id]);
        $site = Site::factory()->create(['agency_id' => $other->id, 'client_id' => $client->id]);

        $this->postJson("/api/v1/sites/{$site->id}/work-logs", ['description' => 'x'])->assertNotFound();
    }
}
