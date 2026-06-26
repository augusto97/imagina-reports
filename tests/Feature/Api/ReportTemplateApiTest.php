<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
use App\Models\ReportDefinition;
use App\Models\ReportTemplate;
use App\Models\Site;
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

    public function test_default_blocks_returns_the_narrative_layout(): void
    {
        $response = $this->getJson('/api/v1/report-templates/default-blocks')->assertOk();

        $this->assertSame(DefaultTemplate::blocks(), $response->json('blocks'));
        $this->assertContains('header', array_column($response->json('blocks'), 'type'));
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

    public function test_store_accepts_and_persists_a_theme(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Con tema',
            'blocks' => DefaultTemplate::blocks(),
            'theme' => ['accent' => '#10b981', 'density' => 'compact'],
        ])
            ->assertCreated()
            ->assertJsonPath('theme.accent', '#10b981')
            ->assertJsonPath('theme.density', 'compact');
    }

    public function test_store_accepts_a_navigation_config(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Con nav',
            'blocks' => DefaultTemplate::blocks(),
            'theme' => ['nav' => ['position' => 'sidebar', 'style' => 'underline', 'collapsible' => true]],
        ])
            ->assertCreated()
            ->assertJsonPath('theme.nav.position', 'sidebar')
            ->assertJsonPath('theme.nav.collapsible', true);
    }

    public function test_store_rejects_an_invalid_navigation_position(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Nav roto',
            'blocks' => DefaultTemplate::blocks(),
            'theme' => ['nav' => ['position' => 'floating']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme.nav.position');
    }

    public function test_store_rejects_an_invalid_theme_density(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Tema roto',
            'blocks' => DefaultTemplate::blocks(),
            'theme' => ['density' => 'enormous'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme.density');
    }

    public function test_store_accepts_named_pages_and_cover_blocks(): void
    {
        $this->postJson('/api/v1/report-templates', [
            'name' => 'Con portada',
            'blocks' => [
                ['id' => 'cv', 'type' => 'cover', 'page' => 0],
                ['id' => 'bc', 'type' => 'back_cover', 'page' => 1],
            ],
            'pages' => [['name' => 'Portada'], ['name' => 'Cierre']],
        ])
            ->assertCreated()
            ->assertJsonPath('pages.0.name', 'Portada')
            ->assertJsonPath('pages.1.name', 'Cierre');

        $template = ReportTemplate::query()->where('name', 'Con portada')->firstOrFail();
        $this->assertSame('Portada', $template->pages[0]['name'] ?? null);
        $this->assertContains('cover', array_column($template->blocks, 'type'));
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

    public function test_it_blocks_deleting_a_template_in_use_by_a_definition(): void
    {
        $template = ReportTemplate::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create([
            'agency_id' => $this->agency->id,
            'client_id' => Client::factory()->create(['agency_id' => $this->agency->id])->id,
        ]);
        ReportDefinition::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
        ]);

        $this->deleteJson("/api/v1/report-templates/{$template->id}")->assertStatus(409);

        $this->assertDatabaseHas('ir_report_templates', ['id' => $template->id]);
    }

    public function test_it_deletes_an_unused_template(): void
    {
        $template = ReportTemplate::factory()->create(['agency_id' => $this->agency->id]);

        $this->deleteJson("/api/v1/report-templates/{$template->id}")->assertOk();

        $this->assertDatabaseMissing('ir_report_templates', ['id' => $template->id]);
    }
}
