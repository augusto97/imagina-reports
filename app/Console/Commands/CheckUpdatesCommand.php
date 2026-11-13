<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppRelease;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registers the latest published GitHub release (its .zip bundle + .sha256 checksum)
 * into ir_app_releases so the in-app updater (CLAUDE.md §12) can show an available
 * version and one-click install it. Scheduled hourly.
 *
 * A PRIVATE repository needs the read-only token saved in Sistema: the API calls carry it,
 * and the bundle is then registered by its API asset URL (downloaded with the same token),
 * because a private release's browser download link only works for a logged-in person.
 */
final class CheckUpdatesCommand extends Command
{
    protected $signature = 'system:check-updates';

    protected $description = 'Register the latest published GitHub release for the in-app updater.';

    /** Why the last check couldn't read GitHub (shown in the System panel); cleared on success. */
    public const ERROR_KEY = 'updater:check_error';

    public function handle(): int
    {
        $repo = config('updater.github_repo');
        $channel = config('updater.channel');

        if (! is_string($repo) || $repo === '') {
            return self::SUCCESS;
        }

        $token = app(OAuthCredentials::class)->githubUpdaterToken();

        try {
            $response = self::github($token)->acceptJson()->get("https://api.github.com/repos/{$repo}/releases/latest");
        } catch (Throwable) {
            return $this->failCheck('No se pudo conectar con GitHub desde el servidor. Vuelve a intentarlo en unos minutos.');
        }

        if ($response->failed()) {
            // A failed check must say so: «al día» while GitHub couldn't be read hid a private repository.
            return $this->failCheck(match (true) {
                $token === '' && $response->status() === 404 => "GitHub no muestra las versiones de {$repo}: el repositorio es privado o no existe. Si es privado, guarda un token de GitHub de solo lectura en «Acceso a GitHub», aquí abajo.",
                $token !== '' && in_array($response->status(), [401, 404], true) => "El token de GitHub no tiene acceso a {$repo} o caducó. Revisa que tenga permiso «Contents: Read-only» sobre ese repositorio.",
                in_array($response->status(), [403, 429], true) => 'GitHub limitó las consultas desde este servidor. Vuelve a intentarlo en una hora.',
                default => 'GitHub respondió con un error (HTTP '.$response->status().'). Vuelve a intentarlo en unos minutos.',
            });
        }

        Cache::forget(self::ERROR_KEY);

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
            // With a token, the API asset URL (a private release's browser link needs a login).
            $url = $this->str(Arr::get($asset, $token !== '' ? 'url' : 'browser_download_url'));

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
                'checksum' => $checksumUrl === '' ? null : $this->fetchChecksum($checksumUrl, $token),
                'released_at' => $this->str(Arr::get($data, 'published_at')) ?: now()->toIso8601String(),
            ],
        );

        $this->info("Registered release v{$version}.");

        return self::SUCCESS;
    }

    private function failCheck(string $message): int
    {
        Cache::put(self::ERROR_KEY, $message, now()->addDay());
        $this->warn($message);

        return self::FAILURE;
    }

    /**
     * A GitHub request, with the read-only token when there is one. An API asset URL also
     * needs `Accept: application/octet-stream` to get the file instead of its description;
     * GitHub then redirects to a signed download link (Guzzle drops the token on that hop).
     */
    public static function github(string $token, bool $file = false): PendingRequest
    {
        $request = Http::timeout($file ? 600 : 30)->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $file ? $request->withHeaders(['Accept' => 'application/octet-stream']) : $request;
    }

    private function fetchChecksum(string $url, string $token): ?string
    {
        $response = str_starts_with($url, 'https://api.github.com/')
            ? self::github($token, true)->timeout(30)->get($url)
            : Http::timeout(30)->get($url);

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
