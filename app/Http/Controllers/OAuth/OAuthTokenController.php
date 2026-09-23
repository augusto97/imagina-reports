<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\OAuth\AuthorizationCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token endpoint: exchanges an authorization code (plus its PKCE verifier) for a Sanctum token
 * carrying exactly the permissions the person approved. Tokens can be revoked any time in
 * Ajustes → Asistentes IA.
 */
final class OAuthTokenController extends Controller
{
    public function __construct(private readonly AuthorizationCodes $codes) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'authorization_code') {
            return self::error('unsupported_grant_type', 'Solo se admite grant_type=authorization_code.');
        }

        $code = $request->input('code');
        $clientId = $request->input('client_id');
        $redirect = $request->input('redirect_uri');
        $verifier = $request->input('code_verifier');

        if (! is_string($code) || ! is_string($clientId) || ! is_string($verifier)) {
            return self::error('invalid_request', 'Faltan code, client_id o code_verifier.');
        }

        $grant = $this->codes->consume($code);
        if ($grant === null
            || ! hash_equals($grant['client_id'], $clientId)
            || (is_string($redirect) && $redirect !== $grant['redirect_uri'])
            || ! AuthorizationCodes::verifierMatches($verifier, $grant['code_challenge'])) {
            return self::error('invalid_grant', 'El código no es válido, ya se usó o caducó.');
        }

        $client = OAuthClient::query()->where('client_id', $clientId)->first();
        $user = User::query()->find($grant['user_id']);

        if ($client === null || $user === null || $user->is_platform_admin || $user->agency_id === null || ! $user->role->isPrivileged()) {
            return self::error('invalid_grant', 'La autorización ya no es válida.');
        }

        $name = mb_substr('Asistente: '.$client->name, 0, 120);
        $token = $user->createToken($name, $grant['abilities']);

        AuditLogger::record(
            AuditLogger::API_TOKEN_CREATED,
            null,
            "Conectó el asistente «{$client->name}» (OAuth)",
            ['abilities' => $grant['abilities'], 'client_id' => $client->client_id],
            $user,
            $user->agency_id,
        );

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'scope' => implode(' ', $grant['abilities']),
        ])->header('Cache-Control', 'no-store')->header('Access-Control-Allow-Origin', '*');
    }

    private static function error(string $code, string $description): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400)
            ->header('Cache-Control', 'no-store')
            ->header('Access-Control-Allow-Origin', '*');
    }
}
