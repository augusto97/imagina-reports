<?php

declare(strict_types=1);

namespace App\Providers;

use App\Connectors\BetterUptime\BetterUptimeConnector;
use App\Connectors\Cloudflare\CloudflareConnector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\CrowdSec\CrowdSecConnector;
use App\Connectors\Database\DatabaseConnector;
use App\Connectors\Endpoint\EndpointConnector;
use App\Connectors\Ga4\Ga4Connector;
use App\Connectors\Google\GoogleTokenProvider;
use App\Connectors\Google\ServiceAccountTokenProvider;
use App\Connectors\Gsc\GscConnector;
use App\Connectors\MainWp\MainWpConnector;
use App\Connectors\SiteAgent\SiteAgentConnector;
use App\Connectors\TrueRanker\TrueRankerConnector;
use App\Connectors\Virusdie\VirusdieConnector;
use App\Connectors\WooCommerce\WooCommerceConnector;
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
        $this->app->bind(GoogleTokenProvider::class, ServiceAccountTokenProvider::class);

        $this->app->singleton(ConnectorRegistry::class, function (Application $app): ConnectorRegistry {
            $registry = new ConnectorRegistry;

            $google = $app->make(GoogleTokenProvider::class);

            // Concrete connectors register here as they are implemented.
            $registry->register(new MainWpConnector);
            $registry->register(new Ga4Connector($google));
            $registry->register(new GscConnector($google));
            $registry->register(new CloudflareConnector);
            $registry->register(new CrowdSecConnector);
            $registry->register(new BetterUptimeConnector);
            $registry->register(new VirusdieConnector);
            $registry->register(new WooCommerceConnector);
            $registry->register(new TrueRankerConnector);
            $registry->register(new DatabaseConnector);
            $registry->register(new EndpointConnector);
            $registry->register(new SiteAgentConnector);

            return $registry;
        });
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [ConnectorRegistry::class, GoogleTokenProvider::class];
    }
}
