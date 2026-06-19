<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\AppRelease;

/**
 * Performs the destructive deploy steps for the self-updater (CLAUDE.md §12.3).
 * Abstracted so the orchestration (UpdateManager) is fully testable and CI never
 * swaps real symlinks — tests bind a fake.
 */
interface Deployer
{
    /**
     * Install a release beside the current one and flip the `current` symlink.
     *
     * @return bool Whether the post-flip /health check passed.
     */
    public function deploy(AppRelease $release): bool;

    /**
     * Point `current` back to the previous release and restore the DB backup.
     */
    public function rollback(): void;
}
