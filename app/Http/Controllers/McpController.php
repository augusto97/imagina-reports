<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The MCP endpoint (Streamable HTTP transport): one POST per JSON-RPC message or batch.
 *
 * Only a real API token authenticates here — never the panel's cookie session — and an
 * unauthenticated call answers 401 with the OAuth discovery pointer MCP clients follow to
 * connect (RFC 9728), which is what makes "add connector → log in → allow" work in Claude.ai.
 */
final class McpController extends Controller
{
    public function __construct(
        private readonly McpServer $server,
        private readonly TenantContext $tenant,
    ) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return $this->unauthorized();
        }

        $user = Auth::guard('sanctum')->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            return $this->unauthorized();
        }

        // Tokens belong to an agency; a platform operator has no agency to act in.
        $agencyId = $user->agency_id;
        if ($agencyId === null || $user->is_platform_admin) {
            return response()->json(['message' => 'Este usuario no pertenece a ninguna agencia.'], 403);
        }

        $this->tenant->set($agencyId);
        $context = new McpContext($user, $token, $bearer, $agencyId);

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }
        $isBatch = array_is_list($payload) && $payload !== [];
        $messages = $isBatch ? $payload : [$payload];

        $responses = [];
        foreach ($messages as $message) {
            $response = $this->server->handle($message, $context);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        // Only notifications / responses from the client: accepted, nothing to answer.
        if ($responses === []) {
            return response()->noContent(202);
        }

        return response()->json($isBatch ? $responses : $responses[0]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()
            ->json(['message' => 'Conecta Imagina Reports con un token o iniciando sesión.'], 401)
            ->header('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/api/v1/mcp').'"');
    }
}
