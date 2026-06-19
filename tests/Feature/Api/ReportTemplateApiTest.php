<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Reports\Templates\DefaultTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    public function test_store_accepts_a_valid_block_layout(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Mensual',
            'blocks' => DefaultTemplate::blocks(),
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Mensual');

        $this->assertDatabaseHas('ir_report_templates', ['name' => 'Mensual', 'agency_id' => $this->agency->id]);
    }

    public function test_store_rejects_an_invalid_block_layout(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Roto',
            'blocks' => [['id' => 'c1', 'type' => 'chart']], // data block without a binding
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('blocks');
    }

    public function test_update_replaces_the_blocks(): void
    {
        $template = ReportTemplate::factory()->create(['agency_id' => $this->agency->id]);

        $this->putJson("/api/v1/report-templates/{$template->id}", [
            'blocks' => [['id' => 'd1', 'type' => 'divider']],
        ])->assertOk();

        $this->assertCount(1, $template->fresh()?->blocks ?? []);
    }

    public function test_it_cannot_view_another_agencys_template(): void
    {
        $other = ReportTemplate::factory()->create(['agency_id' => Agency::factory()->create()->id]);

        $this->getJson("/api/v1/report-templates/{$other->id}")->assertNotFound();
    }
}
