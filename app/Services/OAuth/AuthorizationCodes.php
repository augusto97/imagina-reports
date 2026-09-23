<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived, single-use authorization codes (OAuth 2.1, PKCE mandatory).
 *
 * Stored under a hash of the code, never the code itself, so a cache dump doesn't hand out
 * usable codes; consumed with `pull` so the same code can't be exchanged twice.
 */
final class AuthorizationCodes
{
    private const TTL_MINUTES = 5;

    /**
     * @param  list<string>  $abilities
     */
    public function issue(string $clientId, int $userId, string $redirectUri, string $codeChallenge, array $abilities): string
    {
        $code = Str::random(64);

        Cache::put($this->key($code), [
            'client_id' => $clientId,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'abilities' => $abilities,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    /**
     * Consume a code. Null when unknown, expired or already used.
     *
     * @return array{client_id: string, user_id: int, redirect_uri: string, code_challenge: string, abilities: list<string>}|null
     */
    public function consume(string $code): ?array
    {
        $stored = Cache::pull($this->key($code));

        if (! is_array($stored)) {
            return null;
        }

        $clientId = $stored['client_id'] ?? null;
        $userId = $stored['user_id'] ?? null;
        $redirect = $stored['redirect_uri'] ?? null;
        $challenge = $stored['code_challenge'] ?? null;
        $abilities = $stored['abilities'] ?? null;

        if (! is_string($clientId) || ! is_int($userId) || ! is_string($redirect) || ! is_string($challenge) || ! is_array($abilities)) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'user_id' => $userId,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'abilities' => array_values(array_filter($abilities, 'is_string')),
        ];
    }

    /**
     * PKCE S256: BASE64URL(SHA256(verifier)) must equal the challenge sent at authorization.
     */
    public static function verifierMatches(string $verifier, string $challenge): bool
    {
        // RFC 7636 §4.1: 43–128 chars from the unreserved set.
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }

    private function key(string $code): string
    {
        return 'oauth:code:'.hash('sha256', $code);
    }
}
