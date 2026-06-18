<?php

declare(strict_types=1);

namespace App\Providers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Ga4\Ga4Connector;
use App\Connectors\Ga4\Ga4TokenProvider;
use App\Connectors\Ga4\GoogleServiceAccountTokenProvider;
use App\Connectors\MainWp\MainWpConnector;
use Illuminate\Contracts\Foundation\Application;
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
        $this->app->bind(Ga4TokenProvider::class, GoogleServiceAccountTokenProvider::class);

        $this->app->singleton(ConnectorRegistry::class, function (Application $app): ConnectorRegistry {
            $registry = new ConnectorRegistry;

            // Concrete connectors register here as they are implemented.
            $registry->register(new MainWpConnector);
            $registry->register(new Ga4Connector($app->make(Ga4TokenProvider::class)));

            return $registry;
        });
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [ConnectorRegistry::class, Ga4TokenProvider::class];
    }
}
