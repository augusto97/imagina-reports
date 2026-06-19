<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemUpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_polls_github_on_demand_and_returns_fresh_status(): void
    {
        config(['updater.github_repo' => 'augusto97/imagina-reports', 'updater.channel' => 'stable']);

        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => 'v9.9.9',
                'published_at' => '2026-06-19T00:00:00Z',
                'assets' => [
                    ['name' => 'imagina-reports-9.9.9.zip', 'browser_download_url' => 'https://dl.test/bundle.zip'],
                ],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->postJson('/api/v1/system/update/check')
            ->assertOk()
            ->assertJsonPath('available', '9.9.9')
            ->assertJsonPath('update_available', true);

        $this->assertDatabaseHas('ir_app_releases', ['version' => '9.9.9', 'channel' => 'stable']);
    }

    public function test_check_requires_authentication(): void
    {
        $this->postJson('/api/v1/system/update/check')->assertUnauthorized();
    }
}
