<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dynamic Client Registration (RFC 7591): how Claude, ChatGPT or Cursor introduce themselves
 * before the person authorizes them. Registering grants nothing — access only comes from a
 * logged-in owner/admin approving the consent screen.
 */
final class OAuthRegistrationController extends Controller
{
    private const MAX_REDIRECTS = 10;

    /** Schemes a redirect may never use, whatever the client claims. */
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about'];

    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : $request->all();

        $uris = is_array($body['redirect_uris'] ?? null) ? $body['redirect_uris'] : [];
        $redirects = [];
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! self::acceptableRedirect($uri)) {
                return self::error('invalid_redirect_uri', 'Cada redirect_uri debe ser https, un bucle local http o un esquema propio de la aplicación.');
            }
            $redirects[] = $uri;
        }

        if ($redirects === [] || count($redirects) > self::MAX_REDIRECTS) {
            return self::error('invalid_redirect_uri', 'Indica entre 1 y '.self::MAX_REDIRECTS.' redirect_uris.');
        }

        $method = $body['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            return self::error('invalid_client_metadata', 'Solo se admiten clientes públicos (token_endpoint_auth_method = none) con PKCE.');
        }

        $name = is_string($body['client_name'] ?? null) && trim($body['client_name']) !== ''
            ? Str::limit(trim($body['client_name']), 110, '')
            : 'Asistente IA';

        $client = OAuthClient::query()->create([
            'client_id' => 'irc_'.Str::lower(Str::random(40)),
            'name' => $name,
            'redirect_uris' => array_values(array_unique($redirects)),
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    private static function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        if ($scheme === '' || in_array($scheme, self::FORBIDDEN_SCHEMES, true) || str_contains($uri, '#')) {
            return false;
        }

        if ($scheme === 'https') {
            return isset($parts['host']) && $parts['host'] !== '';
        }

        // Plain http only for loopback — desktop apps listen on localhost for the callback.
        if ($scheme === 'http') {
            return in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }

        // Private-use schemes of native apps (cursor://, vscode://…).
        return preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) === 1;
    }

    private static function error(string $code, string $description): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400);
    }
}
