<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Connect\ConnectRegistry;
use App\Http\Controllers\Controller;
use App\Mail\ReconnectRequestMail;
use App\Models\ConnectInvite;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\AgencyMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Pedir reconexión al cliente» (Etapa 5): a connection dropped (an expired Google or Meta
 * authorisation, a revoked app), so we email the client a link that only lets them reconnect
 * that one source, for a week. The agency never has to ask for passwords.
 */
final class ReconnectRequestController extends Controller
{
    private const DAYS = 7;

    public function store(Request $request, DataSource $dataSource, ConnectRegistry $connect): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'email.required' => 'Indica el correo del cliente.',
            'email.email' => 'Ese correo no parece válido.',
        ]);

        $site = $dataSource->site;
        $agency = $dataSource->agency;
        if ($site === null || $agency === null || $connect->for($dataSource->type->value) === null) {
            return response()->json(['message' => 'Esta fuente no se conecta con un clic: revisa tú su clave o configuración.'], 422);
        }

        $email = $request->string('email')->trim()->lower()->toString();
        $user = $request->user();
        $invite = ConnectInvite::issue($site, [$dataSource->type->value], self::DAYS, $user instanceof User ? $user->id : null);

        $note = $request->string('message')->trim()->toString();
        AgencyMail::toClient($agency, $email, new ReconnectRequestMail($dataSource, $invite, $note !== '' ? $note : null));

        AuditLogger::record(AuditLogger::CONNECT_INVITE_CREATED, $invite, "Pedido de reconexión de {$dataSource->type->label()} para {$site->name}", [
            'types' => $invite->types,
            'sent_to' => $email,
        ]);

        $dataSource->forceFill(['meta' => array_merge($dataSource->meta ?? [], [
            'reconnect_requested_at' => now()->toIso8601String(),
            'reconnect_requested_to' => $email,
        ])])->save();

        return response()->json([
            'url' => $invite->url(),
            'sent_to' => $email,
            'expires_at' => $invite->expires_at->toIso8601String(),
        ], 201);
    }
}
