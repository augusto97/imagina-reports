<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use App\Connectors\Connect\OAuth\MetaOAuthClient;
use Illuminate\Http\Request;

/**
 * One-click "Connect with Facebook" for a Meta-backed source (Facebook/Instagram Ads or
 * Instagram insights). Pure OAuth: the client authorizes on Meta's login dialog and we
 * store a long-lived user access token. Configured per source type with the right read-only
 * scopes; the account is then picked from a dropdown (ListsConnectableResources).
 */
final class MetaConnect implements ConnectProvider
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        private readonly MetaOAuthClient $oauth,
        private readonly string $type,
        private readonly array $scopes,
        private readonly string $label = 'Conectar con Facebook',
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function startFields(): array
    {
        return [];
    }

    public function redirectUrl(array $input, string $nonce, string $callbackUrl, string $returnUrl): string
    {
        return $this->oauth->authorizeUrl($this->scopes, $callbackUrl, $nonce);
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
}
