<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use App\Connectors\Connect\OAuth\MetaOAuthClient;
use App\Enums\DataSourceType;
use Illuminate\Http\Request;

/**
 * One-click "Connect with Facebook" for Facebook/Instagram Ads. Pure OAuth: the client
 * authorizes on Meta's login dialog and we store a long-lived user access token. The ad
 * account is then picked from a dropdown (ListsConnectableResources on the connector).
 */
final class MetaAdsConnect implements ConnectProvider
{
    public function __construct(private readonly MetaOAuthClient $oauth) {}

    public function type(): string
    {
        return DataSourceType::FacebookAds->value;
    }

    public function label(): string
    {
        return 'Conectar con Facebook';
    }

    public function startFields(): array
    {
        return [];
    }

    public function redirectUrl(array $input, string $nonce, string $callbackUrl, string $returnUrl): string
    {
        return $this->oauth->authorizeUrl($callbackUrl, $nonce);
    }

    public function nonceFromCallback(Request $request): string
    {
        $state = $request->query('state');

        return is_string($state) ? $state : '';
    }

    public function callbackIsBrowserRedirect(): bool
    {
        return true;
    }

    public function parseCallback(Request $request, string $callbackUrl): ?ConnectCallback
    {
        $code = $request->query('code');
        $state = $this->nonceFromCallback($request);

        if (! is_string($code) || $code === '' || $state === '') {
            return null;
        }

        $token = $this->oauth->exchangeCode($code, $callbackUrl);

        if ($token === null) {
            return null;
        }

        return new ConnectCallback(
            nonce: $state,
            config: [],
            credentials: ['access_token' => $token],
        );
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }
}
