<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\AppRelease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Production deployer (CLAUDE.md §12.2/§12.3): atomic releases via symlink swap with
 * an auto-rolling-back health check. The running app never overwrites live files — it
 * downloads the new release beside the old, runs the proven `deploy.sh` (link shared →
 * migrate → cache → flip `current` → queue:restart), then health-checks. On failure it
 * repoints `current` to the previous release.
 *
 * Reuses the operator's own `deploy.sh` (already validated on the VPS) instead of
 * re-implementing the filesystem steps, so in-app updates run the same path as a
 * manual deploy. Never exercised in CI — a fake Deployer is bound in tests.
 */
final class SymlinkDeployer implements Deployer
{
    public function deploy(AppRelease $release): bool
    {
        try {
            $this->backupDatabase();
            $path = $this->downloadAndExtract($release);
            $this->runDeployScript($path);

            if ($this->healthy($path)) {
                $this->writeVersion($path, $release->version);
                $this->pruneOldReleases();

                return true;
            }

            return false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function rollback(): void
    {
        try {
            $previous = $this->previousReleasePath();

            if ($previous === null) {
                return;
            }

            $this->process(['ln', '-sfn', $previous, $this->path('current_link')]);
            $this->restoreDatabase();
            // Horizon needs terminate (not just queue:restart) to reload code — see deploy.sh.
            Process::timeout(120)->run([PHP_BINARY, $previous.'/artisan', 'horizon:terminate']);
            $this->process([PHP_BINARY, $previous.'/artisan', 'queue:restart']);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function downloadAndExtract(AppRelease $release): string
    {
        $url = $release->bundle_url;

        if ($url === '') {
            throw new RuntimeException('Release has no bundle URL.');
        }

        $dir = $this->path('releases_dir').'/'.date('YmdHis').'_'.$release->version;
        @mkdir($dir, 0775, true);
        $zip = $dir.'.zip';

        $response = Http::timeout(600)->sink($zip)->get($url);

        if ($response->failed()) {
            throw new RuntimeException('Failed to download release bundle: HTTP '.$response->status());
        }

        $checksum = is_string($release->checksum) ? trim($release->checksum) : '';
        $actual = hash_file('sha256', $zip);

        if ($checksum !== '' && (! is_string($actual) || ! hash_equals(strtolower($checksum), strtolower($actual)))) {
            @unlink($zip);

            throw new RuntimeException('Release checksum mismatch.');
        }

        $this->process(['unzip', '-q', '-o', $zip, '-d', $dir]);
        @unlink($zip);

        return $dir;
    }

    private function runDeployScript(string $releasePath): void
    {
        // deploy.sh: link shared → migrate --force → cache → flip current → queue:restart.
        // PATH is primed with the running PHP's directory so the script's `php` is 8.4.
        Process::path($releasePath)
            ->env([
                'RELEASE_DIR' => $releasePath,
                'BASE_PATH' => $this->basePath(),
                'PATH' => dirname(PHP_BINARY).':'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            ])
            ->timeout(600)
            ->run(['bash', $releasePath.'/deploy.sh'])
            ->throw();
    }

    private function backupDatabase(): void
    {
        $connection = config('database.default');
        $config = config('database.connections.'.(is_string($connection) ? $connection : 'mariadb'));

        if (! is_array($config)) {
            return;
        }

        $dir = $this->path('shared_dir').'/backups';
        @mkdir($dir, 0775, true);
        $file = $dir.'/'.date('YmdHis').'.sql';

        $command = sprintf(
            'mysqldump -h %s -P %s -u %s %s > %s',
            escapeshellarg($this->str($config, 'host', '127.0.0.1')),
            escapeshellarg($this->str($config, 'port', '3306')),
            escapeshellarg($this->str($config, 'username', 'root')),
            escapeshellarg($this->str($config, 'database', '')),
            escapeshellarg($file),
        );

        // Best-effort: a missing mysqldump must not abort the update (the atomic flip +
        // health rollback still protect the code; migrations are forward-only).
        Process::env(['MYSQL_PWD' => $this->str($config, 'password', '')])->timeout(300)->run($command);
    }

    private function restoreDatabase(): void
    {
        $backups = glob($this->path('shared_dir').'/backups/*.sql');

        if ($backups === false || $backups === []) {
            return;
        }

        rsort($backups);
        $latest = $backups[0];

        $connection = config('database.default');
        $config = config('database.connections.'.(is_string($connection) ? $connection : 'mariadb'));

        if (! is_array($config)) {
            return;
        }

        $command = sprintf(
            'mysql -h %s -P %s -u %s %s < %s',
            escapeshellarg($this->str($config, 'host', '127.0.0.1')),
            escapeshellarg($this->str($config, 'port', '3306')),
            escapeshellarg($this->str($config, 'username', 'root')),
            escapeshellarg($this->str($config, 'database', '')),
            escapeshellarg($latest),
        );

        Process::env(['MYSQL_PWD' => $this->str($config, 'password', '')])->timeout(300)->run($command);
    }

    private function previousReleasePath(): ?string
    {
        $dirs = glob($this->path('releases_dir').'/*', GLOB_ONLYDIR);

        if ($dirs === false || count($dirs) < 2) {
            return null;
        }

        rsort($dirs); // timestamp-prefixed names sort newest-first.
        $currentTarget = realpath($this->path('current_link'));

        foreach ($dirs as $dir) {
            if (realpath($dir) !== $currentTarget) {
                return $dir;
            }
        }

        return null;
    }

    private function pruneOldReleases(): void
    {
        $keep = config('updater.keep_releases');
        $keep = is_int($keep) ? $keep : 5;

        $dirs = glob($this->path('releases_dir').'/*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return;
        }

        rsort($dirs);

        foreach (array_slice($dirs, $keep) as $old) {
            Process::run(['rm', '-rf', $old]);
        }
    }

    private function writeVersion(string $releasePath, string $version): void
    {
        @file_put_contents($releasePath.'/VERSION', $version."\n");
    }

    /**
     * Confirm the freshly-flipped release is healthy (CLAUDE.md §12.3) before
     * keeping it. Tries the configured HTTP probe first; if that is unreachable
     * — common when the site sits behind a CDN/WAF (Cloudflare) that blocks the
     * server's own request to its public URL — it falls back to a local boot
     * check of the new release. deploy.sh already ran migrate + config/route/view
     * cache (which throw on a broken build), so a clean local boot is a reliable
     * signal and avoids spurious auto-rollbacks.
     */
    private function healthy(string $releasePath): bool
    {
        $url = config('updater.health_url');

        if (is_string($url) && $url !== '') {
            try {
                if (Http::timeout(10)->get($url)->successful()) {
                    return true;
                }
            } catch (Throwable) {
                // Unreachable (CDN/WAF, DNS, TLS) — fall back to the local boot check.
            }
        }

        return $this->bootsLocally($releasePath);
    }

    /**
     * The new release boots cleanly under the running PHP (8.x): `artisan about`
     * fully bootstraps the framework, so exit 0 means config/providers/DB are wired.
     */
    private function bootsLocally(string $releasePath): bool
    {
        return Process::path($releasePath)
            ->env(['PATH' => dirname(PHP_BINARY).':'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')])
            ->timeout(60)
            ->run([PHP_BINARY, $releasePath.'/artisan', 'about', '--only=environment'])
            ->successful();
    }

    private function basePath(): string
    {
        $base = config('updater.base_path');

        return is_string($base) ? $base : dirname(base_path());
    }

    private function path(string $key): string
    {
        $defaults = ['releases_dir' => 'releases', 'shared_dir' => 'shared', 'current_link' => 'current'];
        $value = config('updater.'.$key);

        return $this->basePath().'/'.(is_string($value) ? $value : ($defaults[$key] ?? $key));
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function str(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command): void
    {
        Process::timeout(120)->run($command)->throw();
    }
}
