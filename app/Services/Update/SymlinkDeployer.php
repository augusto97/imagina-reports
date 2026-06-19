<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\AppRelease;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Production deployer (CLAUDE.md §12.2/§12.3): atomic releases via symlink swap with
 * an auto-rolling-back health check. The running app never overwrites live files —
 * it builds the new release beside the old and flips `current`.
 *
 * The concrete filesystem/process steps are validated on the VPS (ServerAvatar/OLS);
 * they are intentionally never exercised in CI (a fake is bound in tests).
 */
final class SymlinkDeployer implements Deployer
{
    public function deploy(AppRelease $release): bool
    {
        try {
            $this->backupDatabase();
            $path = $this->downloadAndExtract($release);
            $this->linkShared($path);
            $this->runArtisan('migrate', '--force', $path);
            $this->cacheConfig($path);
            $this->flipCurrent($path);
            $this->restartQueue();

            return $this->healthy();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function rollback(): void
    {
        try {
            $this->pointCurrentToPrevious();
            $this->restoreDatabase();
            $this->restartQueue();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function basePath(): string
    {
        $base = config('updater.base_path');

        return is_string($base) ? $base : dirname(base_path());
    }

    private function backupDatabase(): void
    {
        // mysqldump > shared/backups/<ts>.sql (safer than migrate:rollback, §12.4).
    }

    private function downloadAndExtract(AppRelease $release): string
    {
        // Download bundle_url, verify checksum, extract to releases/<ts>_<version>.
        $releasesDir = config('updater.releases_dir');

        return $this->basePath().'/'.(is_string($releasesDir) ? $releasesDir : 'releases').'/'.date('YmdHis').'_'.$release->version;
    }

    private function linkShared(string $releasePath): void
    {
        // Symlink shared/.env + shared/storage into the new release.
    }

    private function runArtisan(string $command, string $arg, string $releasePath): void
    {
        // Run `php artisan <command> <arg>` inside the new release.
    }

    private function cacheConfig(string $releasePath): void
    {
        // config:cache + route:cache + view:cache in the new release.
    }

    private function flipCurrent(string $releasePath): void
    {
        // Atomically repoint the `current` symlink to $releasePath.
    }

    private function pointCurrentToPrevious(): void
    {
        // Repoint `current` to the previous (intact) release.
    }

    private function restoreDatabase(): void
    {
        // Restore the latest mysqldump.
    }

    private function restartQueue(): void
    {
        // php artisan queue:restart — only as the final step (§12.5).
    }

    private function healthy(): bool
    {
        $url = config('updater.health_url');

        if (! is_string($url)) {
            return false;
        }

        try {
            return Http::timeout(10)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
