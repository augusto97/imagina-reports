<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Registers the latest published GitHub release (its .zip bundle + .sha256 checksum)
 * into ir_app_releases so the in-app updater (CLAUDE.md §12) can show an available
 * version and one-click install it. Scheduled hourly.
 */
final class CheckUpdatesCommand extends Command
{
    protected $signature = 'system:check-updates';

    protected $description = 'Register the latest published GitHub release for the in-app updater.';

    public function handle(): int
    {
        $repo = config('updater.github_repo');
        $channel = config('updater.channel');

        if (! is_string($repo) || $repo === '') {
            return self::SUCCESS;
        }

        $response = Http::acceptJson()->timeout(30)->get("https://api.github.com/repos/{$repo}/releases/latest");

        if ($response->failed()) {
            $this->warn('Could not reach GitHub: HTTP '.$response->status());

            return self::FAILURE;
        }

        $data = is_array($response->json()) ? $response->json() : [];
        $version = ltrim($this->str(Arr::get($data, 'tag_name')), 'v');

        if ($version === '') {
            $this->warn('No release tag found.');

            return self::SUCCESS;
        }

        $bundleUrl = '';
        $checksumUrl = '';
        $assets = Arr::get($data, 'assets');

        foreach (is_array($assets) ? $assets : [] as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = $this->str(Arr::get($asset, 'name'));
            $url = $this->str(Arr::get($asset, 'browser_download_url'));

            if (str_ends_with($name, '.zip')) {
                $bundleUrl = $url;
            } elseif (str_ends_with($name, '.sha256')) {
                $checksumUrl = $url;
            }
        }

        if ($bundleUrl === '') {
            $this->warn('Latest release has no .zip asset.');

            return self::SUCCESS;
        }

        AppRelease::query()->updateOrCreate(
            ['version' => $version, 'channel' => is_string($channel) ? $channel : 'stable'],
            [
                'bundle_url' => $bundleUrl,
                'checksum' => $checksumUrl === '' ? null : $this->fetchChecksum($checksumUrl),
                'released_at' => $this->str(Arr::get($data, 'published_at')) ?: now()->toIso8601String(),
            ],
        );

        $this->info("Registered release v{$version}.");

        return self::SUCCESS;
    }

    private function fetchChecksum(string $url): ?string
    {
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            return null;
        }

        // The .sha256 file is "<hash>  <filename>"; keep only the hash.
        $hash = trim((string) strtok($response->body(), " \t\n"));

        return $hash === '' ? null : $hash;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
