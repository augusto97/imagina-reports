<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

use App\Connectors\SetupGuide;

/**
 * Optional capability: a connector that ships a "how to connect" guide for the
 * admin UI. The connectors endpoint exposes it when present (CLAUDE.md §7/§11.1).
 */
interface ProvidesSetupGuide
{
    public function setupGuide(): SetupGuide;
}
