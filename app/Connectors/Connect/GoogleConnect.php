<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use App\Connectors\Connect\OAuth\GoogleOAuthClient;
use Illuminate\Http\Request;

/**
 * One-click "Connect with Google" for a Google-backed source (GA4, Search Console, Google
 * Ads). Pure OAuth 2.0: nothing is needed up front — the client authorizes on Google's
 * consent screen and we store the resulting refresh token. Configured per source type with
 * the right read-only scope; a single Google OAuth app (services.google_oauth) serves all.
 */
final class GoogleConnect implements ConnectProvider
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly string $type,
        private readonly string $label,
        private readonly array $scopes,
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

        $refresh = $this->oauth->exchangeCode($code, $callbackUrl);

        if ($refresh === null) {
            return null;
        }

        return new ConnectCallback(
            nonce: $state,
            config: [],
            credentials: ['oauth_refresh_token' => $refresh],
        );
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }
}
