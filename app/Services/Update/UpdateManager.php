<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\AppRelease;

/**
 * Orchestrates the self-update flow (CLAUDE.md §12.3/§12.4). All destructive work
 * is delegated to a Deployer; this class is pure logic and fully testable.
 */
final readonly class UpdateManager
{
    public function __construct(private Deployer $deployer) {}

    public function currentVersion(): string
    {
        $version = config('app.version');

        return is_string($version) ? $version : '0.0.0';
    }

    public function availableRelease(): ?AppRelease
    {
        $channel = config('updater.channel');

        return AppRelease::query()
            ->where('channel', is_string($channel) ? $channel : 'stable')
            ->orderByDesc('released_at')
            ->first();
    }

    /**
     * @return array{current: string, available: string|null, update_available: bool}
     */
    public function status(): array
    {
        $current = $this->currentVersion();
        $release = $this->availableRelease();

        return [
            'current' => $current,
            'available' => $release?->version,
            'update_available' => $release !== null && version_compare($release->version, $current, '>'),
        ];
    }

    /**
     * Install the latest available release, auto-rolling back on a failed health check.
     */
    public function update(): bool
    {
        $release = $this->availableRelease();

        if ($release === null) {
            return false;
        }

        if ($this->deployer->deploy($release)) {
            return true;
        }

        $this->deployer->rollback();

        return false;
    }

    public function rollback(): void
    {
        $this->deployer->rollback();
    }
}
