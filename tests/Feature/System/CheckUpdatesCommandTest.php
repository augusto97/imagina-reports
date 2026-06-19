<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGithub(): void
    {
        config(['updater.github_repo' => 'augusto97/imagina-reports', 'updater.channel' => 'stable']);

        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => 'v1.2.3',
                'published_at' => '2026-06-19T00:00:00Z',
                'assets' => [
                    ['name' => 'imagina-reports-1.2.3.zip', 'browser_download_url' => 'https://dl.test/bundle.zip'],
                    ['name' => 'imagina-reports-1.2.3.zip.sha256', 'browser_download_url' => 'https://dl.test/bundle.zip.sha256'],
                ],
            ]),
            'dl.test/bundle.zip.sha256' => Http::response("abc123def456  imagina-reports-1.2.3.zip\n"),
        ]);
    }

    public function test_it_registers_the_latest_release(): void
    {
        $this->fakeGithub();

        $this->artisan('system:check-updates')->assertSuccessful();

        $this->assertDatabaseHas('ir_app_releases', [
            'version' => '1.2.3',
            'channel' => 'stable',
            'bundle_url' => 'https://dl.test/bundle.zip',
            'checksum' => 'abc123def456',
        ]);
    }

    public function test_it_is_idempotent(): void
    {
        $this->fakeGithub();

        $this->artisan('system:check-updates')->assertSuccessful();
        $this->artisan('system:check-updates')->assertSuccessful();

        $this->assertSame(1, AppRelease::query()->where('version', '1.2.3')->count());
    }
}
