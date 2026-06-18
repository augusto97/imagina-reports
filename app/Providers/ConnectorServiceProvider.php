<?php

declare(strict_types=1);

namespace App\Providers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MainWp\MainWpConnector;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the connector layer (CLAUDE.md §7). Binds the ConnectorRegistry as a
 * singleton; concrete connectors (MainWP, GA4, GSC, …) register themselves here
 * in later Phase 1 tasks.
 */
class ConnectorServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(ConnectorRegistry::class, function (): ConnectorRegistry {
            $registry = new ConnectorRegistry;

            // Concrete connectors register here as they are implemented.
            $registry->register(new MainWpConnector);

            return $registry;
        });
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [ConnectorRegistry::class];
    }
}
