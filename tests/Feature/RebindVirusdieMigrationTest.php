<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\ReportTemplate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saved layouts that showed Virusdie's scan count as «Malware detectado» now bind the scans
 * metric and say what it counts.
 */
class RebindVirusdieMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_templates_are_rebound_to_the_scans_metric(): void
    {
        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $template = ReportTemplate::factory()->create([
            'agency_id' => $agency->id,
            'blocks' => [
                ['id' => 'm', 'type' => 'kpi', 'binding' => ['source' => 'mainwp', 'metric' => 'malware_found'], 'props' => ['title' => 'Malware detectado'], 'style' => []],
                ['id' => 'v', 'type' => 'kpi', 'binding' => ['source' => 'mainwp', 'metric' => 'vulnerabilities_count'], 'props' => ['title' => 'Vulnerabilidades'], 'style' => []],
            ],
        ]);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_12_000100_rebind_virusdie_malware_to_scans.php');
        $migration->up();

        $blocks = $template->refresh()->blocks;
        $this->assertSame('virusdie_scans', $blocks[0]['binding']['metric']);
        $this->assertSame('Escaneos antimalware', $blocks[0]['props']['title']);
        $this->assertSame('vulnerabilities_count', $blocks[1]['binding']['metric']);
    }
}
