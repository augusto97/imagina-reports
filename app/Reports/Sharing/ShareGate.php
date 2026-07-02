<?php

declare(strict_types=1);

namespace App\Reports\Sharing;

use App\Enums\ReportVisibility;
use App\Models\ReportDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Enforces a report definition's sharing settings (CLAUDE.md §10/Etapa D): private →
 * 403, password → 401 until the right password arrives. Shared by the frozen-report
 * endpoint and the live dashboard so both gate identically. Returns null when access
 * is allowed, or the denial JsonResponse otherwise.
 */
final class ShareGate
{
    public static function deny(?ReportDefinition $definition, Request $request): ?JsonResponse
    {
        // No definition (e.g. an ad-hoc report) → treated as public.
        if ($definition === null) {
            return null;
        }

        // A suspended (unpaid) agency's public surface goes dark entirely — portal,
        // dashboards, embeds. Already-emailed PDFs are the only thing that survives:
        // a suspended agency must not keep consuming the platform (SaaS Fase 2).
        if ($definition->agency?->isSuspended() === true) {
            return response()->json(['message' => 'Este informe no está disponible en este momento.'], 402);
        }

        $visibility = $definition->visibility ?? ReportVisibility::Public;

        if ($visibility === ReportVisibility::Private) {
            return response()->json(['message' => 'Este informe es privado y no está disponible públicamente.'], 403);
        }

        if ($visibility === ReportVisibility::Password) {
            $provided = $request->header('X-Report-Password') ?: $request->query('password');
            $hash = $definition->password_hash;

            if (! is_string($provided) || ! is_string($hash) || $hash === '' || ! Hash::check($provided, $hash)) {
                return response()->json(['requires_password' => true, 'message' => 'Este informe está protegido con contraseña.'], 401);
            }
        }

        return null;
    }
}
