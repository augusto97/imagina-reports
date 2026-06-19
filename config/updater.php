<?php

declare(strict_types=1);

return [

    // Release channel + source (CLAUDE.md §12/§16).
    'channel' => env('UPDATER_CHANNEL', 'stable'),
    'source' => env('UPDATER_SOURCE', 'imagina-updater'),

    // GitHub repo polled by `system:check-updates` to register the latest release
    // (its .zip bundle + .sha256 checksum) so the in-app updater can offer it.
    'github_repo' => env('UPDATER_GITHUB_REPO', 'augusto97/imagina-reports'),

    // Atomic release layout (CLAUDE.md §12.2). `base` holds releases/, shared/ and
    // the `current` symlink (OLS webroot points at current/public).
    'base_path' => env('UPDATER_BASE_PATH', dirname(base_path())),
    'releases_dir' => 'releases',
    'shared_dir' => 'shared',
    'current_link' => 'current',

    // How many old releases to keep for manual rollback.
    'keep_releases' => (int) env('UPDATER_KEEP_RELEASES', 5),

    // Cheap health endpoint checked after the symlink flip (CLAUDE.md §12.3/§12.5).
    'health_url' => env('UPDATER_HEALTH_URL', env('APP_URL', 'http://localhost').'/up'),

];
