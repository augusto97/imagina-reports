<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Console\Commands\CheckUpdatesCommand;
use App\Models\AppRelease;
use App\Models\PlatformSetting;
use App\Services\Platform\OAuthCredentials;
use App\Services\Update\UpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

    public function test_a_private_repository_says_why_no_version_shows(): void
    {
        config(['updater.github_repo' => 'augusto97/imagina-reports', 'updater.github_token' => '']);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->artisan('system:check-updates')->assertFailed();

        // The panel says it couldn't check (and why) instead of «al día».
        $error = app(UpdateManager::class)->status()['check_error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('privado', $error);
    }

    public function test_with_a_token_it_reads_a_private_repository_and_downloads_with_it(): void
    {
        config(['updater.github_repo' => 'augusto97/imagina-reports', 'updater.channel' => 'stable']);
        $settings = PlatformSetting::current();
        $settings->putSecret(OAuthCredentials::GITHUB_UPDATER_TOKEN, 'github_pat_test');
        $settings->save();
        Cache::put(CheckUpdatesCommand::ERROR_KEY, 'old error');

        Http::fake([
            'api.github.com/repos/augusto97/imagina-reports/releases/latest' => Http::response([
                'tag_name' => '1.2.4',
                'published_at' => '2026-06-20T00:00:00Z',
                'assets' => [
                    ['name' => 'imagina-reports-1.2.4.zip', 'url' => 'https://api.github.com/repos/augusto97/imagina-reports/releases/assets/11', 'browser_download_url' => 'https://github.com/x.zip'],
                    ['name' => 'imagina-reports-1.2.4.zip.sha256', 'url' => 'https://api.github.com/repos/augusto97/imagina-reports/releases/assets/12', 'browser_download_url' => 'https://github.com/x.zip.sha256'],
                ],
            ]),
            'api.github.com/repos/augusto97/imagina-reports/releases/assets/12' => Http::response("feedbeef  imagina-reports-1.2.4.zip\n"),
        ]);

        $this->artisan('system:check-updates')->assertSuccessful();

        // Registered by its API asset URL (downloaded later with the token), checksum read with it.
        $this->assertDatabaseHas('ir_app_releases', [
            'version' => '1.2.4',
            'bundle_url' => 'https://api.github.com/repos/augusto97/imagina-reports/releases/assets/11',
            'checksum' => 'feedbeef',
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/releases/latest')
            && $request->hasHeader('Authorization', 'Bearer github_pat_test'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/assets/12')
            && $request->hasHeader('Accept', 'application/octet-stream'));
        $this->assertNull(Cache::get(CheckUpdatesCommand::ERROR_KEY));
    }
}
