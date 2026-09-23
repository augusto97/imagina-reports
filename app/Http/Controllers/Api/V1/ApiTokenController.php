<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiTokenRequest;
use App\Mcp\McpAbility;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Connector tokens for AI assistants (the MCP endpoint). Owners and admins see and revoke every
 * token of their agency — including those other members created and those issued by the
 * "connect with Claude/ChatGPT" OAuth flow — because a person who leaves must be cut off by
 * whoever stays.
 */
final class ApiTokenController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePrivileged($request);

        $users = $this->agencyUsers();
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', array_keys($users))
            ->latest()
            ->get()
            ->map(fn (PersonalAccessToken $token): array => $this->present($token, $users))
            ->values()
            ->all();

        return response()->json([
            'tokens' => $tokens,
            'abilities' => array_map(
                static fn (McpAbility $ability): array => ['value' => $ability->value, 'label' => $ability->label()],
                McpAbility::cases(),
            ),
            'mcp_url' => url('/api/v1/mcp'),
        ]);
    }

    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $name = $request->string('name')->toString();

        $created = $user->createToken($name, $request->abilities());

        AuditLogger::record(AuditLogger::API_TOKEN_CREATED, null, "Creó el token de asistente «{$name}».", ['abilities' => $request->abilities()], $user);

        return response()->json([
            // Shown once: only its hash is stored.
            'plain_text_token' => $created->plainTextToken,
            'token' => $this->present($created->accessToken, $this->agencyUsers()),
        ], 201);
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $this->authorizePrivileged($request);

        $users = $this->agencyUsers();
        $record = PersonalAccessToken::query()
            ->whereKey($token)
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', array_keys($users))
            ->first();

        abort_if($record === null, 404);

        $name = is_string($record->getAttribute('name')) ? $record->getAttribute('name') : '';
        $record->delete();

        AuditLogger::record(AuditLogger::API_TOKEN_REVOKED, null, "Revocó el token de asistente «{$name}».");

        return response()->json(null, 204);
    }

    /**
     * @return array<int, string>
     */
    private function agencyUsers(): array
    {
        $users = [];
        foreach (User::query()->where('agency_id', $this->tenant->id())->get(['id', 'name']) as $user) {
            $users[$user->id] = $user->name;
        }

        return $users;
    }

    /**
     * @param  array<int, string>  $users
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $token, array $users): array
    {
        $owner = $token->getAttribute('tokenable_id');

        return [
            'id' => $token->getKey(),
            'name' => $token->getAttribute('name'),
            'abilities' => $token->getAttribute('abilities'),
            'created_by' => is_int($owner) ? ($users[$owner] ?? null) : null,
            'last_used_at' => self::iso($token->getAttribute('last_used_at')),
            'created_at' => self::iso($token->getAttribute('created_at')),
        ];
    }

    private static function iso(mixed $date): ?string
    {
        return $date instanceof \DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }
}
