<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Mcp\McpAbility;
use Illuminate\Http\JsonResponse;

/**
 * Discovery documents MCP clients read to connect on their own: the MCP endpoint's protected
 * resource metadata (RFC 9728) pointing at this app as its authorization server, and that
 * server's metadata (RFC 8414).
 */
final class OAuthMetadataController extends Controller
{
    public function protectedResource(): JsonResponse
    {
        return $this->json([
            'resource' => url('/api/v1/mcp'),
            'resource_name' => 'Imagina Reports',
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => McpAbility::values(),
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return $this->json([
            'issuer' => self::issuer(),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/api/v1/oauth/token'),
            'registration_endpoint' => url('/api/v1/oauth/register'),
            'scopes_supported' => McpAbility::values(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    public static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(array $body): JsonResponse
    {
        // Browser-based MCP clients fetch these cross-origin.
        return response()->json($body)->header('Access-Control-Allow-Origin', '*');
    }
}
