<?php

declare(strict_types=1);

namespace Tests\Support\Update;

use App\Models\AppRelease;
use App\Services\Update\Deployer;

/**
 * Stub deployer so updater tests never touch the filesystem or swap symlinks.
 */
final class FakeDeployer implements Deployer
{
    public bool $deployed = false;

    public bool $rolledBack = false;

    public function __construct(private readonly bool $healthy = true) {}

    public function deploy(AppRelease $release): bool
    {
        $this->deployed = true;

        return $this->healthy;
    }

    public function rollback(): void
    {
        $this->rolledBack = true;
    }
}
