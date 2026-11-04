<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Connect\ConnectRegistry;
use App\Http\Controllers\ConnectInvitePageController;
use App\Http\Controllers\Controller;
use App\Models\ConnectInvite;
use App\Models\Site;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connection links for clients (admin side): create one for a site and a set of one-click
 * sources, list the active ones, revoke. The public page that uses them is
 * {@see ConnectInvitePageController}.
 */
final class ConnectInviteController extends Controller
{
    /** Allowed lifetimes, in days. Short by default: the link lets someone write credentials. */
    private const EXPIRY_DAYS = [1, 7, 30];

    public function __construct(private readonly ConnectRegistry $registry) {}

    public function index(Site $site): JsonResponse
    {
        $invites = ConnectInvite::query()
            ->with('creator:id,name')
            ->where('site_id', $site->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get();

        return response()->json(['data' => $invites->map(fn (ConnectInvite $invite): array => $this->row($invite))->values()]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $available = array_keys($this->registry->all());

        /** @var array{types: array<int, string>, expires_days: int|string} $data */
        $data = $request->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', 'distinct', Rule::in($available)],
            'expires_days' => ['required', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ], [
            'types.required' => 'Elige al menos una fuente para que el cliente conecte.',
            'types.*.in' => 'Esa fuente no admite conexión con un clic.',
        ]);

        $token = ConnectInvite::newToken();
        $user = $request->user();

        $invite = ConnectInvite::query()->create([
            'site_id' => $site->id,
            'token' => $token,
            'token_hash' => ConnectInvite::hashToken($token),
            'types' => array_values($data['types']),
            'expires_at' => now()->addDays((int) $data['expires_days']),
            'created_by' => $user?->getAuthIdentifier(),
        ]);
        $invite->load('creator:id,name');

        AuditLogger::record(AuditLogger::CONNECT_INVITE_CREATED, $invite, "Enlace de conexión para {$site->name}", ['types' => $invite->types]);

        return response()->json(['data' => $this->row($invite)], 201);
    }

    public function destroy(ConnectInvite $connectInvite): JsonResponse
    {
        $connectInvite->forceFill(['revoked_at' => now()])->save();

        AuditLogger::record(AuditLogger::CONNECT_INVITE_REVOKED, $connectInvite, 'Enlace de conexión desactivado');

        return response()->json(['revoked' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ConnectInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'site_id' => $invite->site_id,
            'url' => $invite->url(),
            'types' => $invite->types,
            'expires_at' => $invite->expires_at->toIso8601String(),
            'last_used_at' => $invite->last_used_at?->toIso8601String(),
            'created_at' => $invite->created_at->toIso8601String(),
            'created_by' => $invite->creator?->name,
        ];
    }
}
