<?php

declare(strict_types=1);

namespace App\Providers;

use App\Connectors\BetterUptime\BetterUptimeConnector;
use App\Connectors\Cloudflare\CloudflareConnector;
use App\Connectors\Connect\ConnectRegistry;
use App\Connectors\Connect\GoogleConnect;
use App\Connectors\Connect\MetaConnect;
use App\Connectors\Connect\OAuth\GoogleOAuthClient;
use App\Connectors\Connect\OAuth\MetaOAuthClient;
use App\Connectors\Connect\WooCommerceConnect;
use App\Connectors\ConnectorRegistry;
use App\Connectors\CrowdSec\CrowdSecConnector;
use App\Connectors\Database\DatabaseConnector;
use App\Connectors\Endpoint\EndpointConnector;
use App\Connectors\FacebookAds\FacebookAdsConnector;
use App\Connectors\Ga4\Ga4Connector;
use App\Connectors\Google\GoogleTokenProvider;
use App\Connectors\Google\ServiceAccountTokenProvider;
use App\Connectors\GoogleAds\GoogleAdsConnector;
use App\Connectors\Gsc\GscConnector;
use App\Connectors\Instagram\InstagramConnector;
use App\Connectors\Mailchimp\MailchimpConnector;
use App\Connectors\MainWp\MainWpConnector;
use App\Connectors\SiteAgent\SiteAgentConnector;
use App\Connectors\TikTokAds\TikTokAdsConnector;
use App\Connectors\TrueRanker\TrueRankerConnector;
use App\Connectors\Virusdie\VirusdieConnector;
use App\Connectors\WooCommerce\WooCommerceConnector;
use App\Enums\DataSourceType;
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
            $registry->register(new GoogleAdsConnector);
            $registry->register(new FacebookAdsConnector);
            $registry->register(new TikTokAdsConnector);
            $registry->register(new MailchimpConnector);
            $registry->register(new InstagramConnector);
            $registry->register(new DatabaseConnector);
            $registry->register(new EndpointConnector);
            $registry->register(new SiteAgentConnector);

            return $registry;
        });

        // One-click connect providers (the alternative to the manual configSchema form).
        // A type without a provider simply has no "Connect" button — manual entry still works.
        $this->app->singleton(ConnectRegistry::class, function (): ConnectRegistry {
            $registry = new ConnectRegistry;

            // WooCommerce is always available — no third-party app to configure.
            $registry->register(new WooCommerceConnect);

            // Google (GA4, GSC, Ads) and Meta only appear once their platform OAuth app is
            // configured (services.google_oauth / services.meta_oauth) — no dead buttons.
            $google = new GoogleOAuthClient;
            if ($google->isConfigured()) {
                $registry->register(new GoogleConnect($google, DataSourceType::Ga4->value, 'Conectar con Google', ['https://www.googleapis.com/auth/analytics.readonly']));
                $registry->register(new GoogleConnect($google, DataSourceType::Gsc->value, 'Conectar con Google', ['https://www.googleapis.com/auth/webmasters.readonly']));
                $registry->register(new GoogleConnect($google, DataSourceType::GoogleAds->value, 'Conectar con Google', ['https://www.googleapis.com/auth/adwords']));
            }

            $meta = new MetaOAuthClient;
            if ($meta->isConfigured()) {
                $registry->register(new MetaConnect($meta, DataSourceType::FacebookAds->value, ['ads_read']));
                $registry->register(new MetaConnect(
                    $meta,
                    DataSourceType::Instagram->value,
                    ['instagram_basic', 'pages_show_list', 'pages_read_engagement', 'instagram_manage_insights'],
                ));
            }

            return $registry;
        });
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [ConnectorRegistry::class, GoogleTokenProvider::class, ConnectRegistry::class];
    }
}
