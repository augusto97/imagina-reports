<?php

declare(strict_types=1);

namespace App\Connectors\Connect\OAuth;

use App\Connectors\Connect\ConnectFailed;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Support\Facades\Http;

/**
 * Google OAuth 2.0 helper for the one-click "Connect with Google" flow (GA4, Search
 * Console, Google Ads). Builds the consent URL, exchanges the returned code for a
 * long-lived refresh token, and mints short-lived access tokens from it for the
 * connectors. The OAuth app's client_id/secret are platform-level (super-admin panel,
 * falling back to services.google_oauth), configured once by the operator — never per client.
 */
final class GoogleOAuthClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(private readonly OAuthCredentials $credentials = new OAuthCredentials) {}

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * The consent screen URL. `access_type=offline` + `prompt=consent` force a refresh
     * token back even on re-authorization, so we can keep syncing without the client.
     *
     * @param  list<string>  $scopes
     */
    public function authorizeUrl(array $scopes, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * Exchange the authorization code for the refresh token we store.
     *
     * Throws with the actual cause when Google refuses — a wrong client secret, a redirect URI
     * that isn't registered, or a consent where the person left a permission unchecked (Google
     * shows each scope as its own checkbox, and the connection is useless without it).
     *
     * @param  list<string>  $requiredScopes
     *
     * @throws ConnectFailed
     */
    public function exchangeCode(string $code, string $redirectUri, array $requiredScopes = []): string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            $error = $response->json('error');
            $description = $response->json('error_description');

            throw new ConnectFailed(self::tokenError(
                is_string($error) ? $error : '',
                is_string($description) ? $description : '',
                $redirectUri,
            ));
        }

        $granted = $response->json('scope');
        if (is_string($granted)) {
            $grantedScopes = preg_split('/\s+/', trim($granted)) ?: [];
            foreach ($requiredScopes as $scope) {
                if (! in_array($scope, $grantedScopes, true)) {
                    throw new ConnectFailed('En la pantalla de Google no quedó marcada la casilla del permiso de lectura que necesita esta fuente. '
                        .'Vuelve a pulsar «Conectar con Google» y marca todas las casillas antes de continuar.');
                }
            }
        }

        $refresh = $response->json('refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new ConnectFailed('Google no entregó un acceso permanente. Quita la app en myaccount.google.com/permissions y vuelve a conectar.');
        }

        return $refresh;
    }

    /**
     * Google's answer when the person comes back without a code (`?error=` on the callback).
     */
    public static function authorizationError(string $error): string
    {
        return match ($error) {
            'access_denied' => 'Cancelaste la autorización en Google, o tu app de Google está en modo de prueba y esta cuenta no figura como usuario de prueba. '
                .'Vuelve a pulsar «Conectar con Google» y acepta el acceso de solo lectura.',
            'admin_policy_enforced' => 'El administrador de Google Workspace de esa cuenta no permite conectar aplicaciones externas.',
            'org_internal' => 'La app de Google está configurada como «Interna» y solo admite cuentas de su propia organización. Cámbiala a «External» en Google Auth Platform → Audience.',
            default => "Google no completó la autorización (error «{$error}»).",
        };
    }

    private static function tokenError(string $error, string $description, string $redirectUri): string
    {
        return match ($error) {
            'redirect_uri_mismatch' => "La URL de retorno no está registrada en el cliente OAuth de Google. Añade {$redirectUri} en Google Auth Platform → Clients → Authorized redirect URIs.",
            'invalid_client', 'unauthorized_client' => 'Google rechazó el Client ID o el Client secret. Revisa que ambos sean del mismo cliente OAuth (tipo «Web application») en Plataforma → Integraciones.',
            'invalid_grant' => 'El código de Google caducó o ya se usó. Vuelve a pulsar «Conectar con Google».',
            '' => 'Google no respondió al canjear la autorización. Vuelve a intentarlo en un momento.',
            default => trim("Google rechazó la conexión ({$error}). {$description}"),
        };
    }

    /** Mint a short-lived access token from a stored refresh token (used by the connectors). */
    public function accessTokenFromRefresh(string $refreshToken): ?string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function clientId(): string
    {
        return $this->credentials->googleClientId();
    }

    private function clientSecret(): string
    {
        return $this->credentials->googleClientSecret();
    }
}
