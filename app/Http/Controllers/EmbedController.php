<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Response;

/**
 * Serves a report for private embedding inside a client's own site (CLAUDE.md §11/Etapa D).
 * The page is the same shared BlockRenderer SPA, but it ships a Content-Security-Policy
 * `frame-ancestors` header built from the definition's embed-domain allowlist — so only
 * the agency-approved sites can iframe it. With no allowlist, embedding is refused.
 *
 * The data still flows through the public API gate (password/private), so embedding never
 * widens access; the allowlist is an additional, browser-enforced restriction.
 */
final class EmbedController extends Controller
{
    public function show(string $token): Response
    {
        $report = Report::query()
            ->withoutGlobalScopes()
            ->with(['definition', 'agency'])
            ->where('public_token', $token)
            ->firstOrFail();

        // Suspended (unpaid) agency → no public embedding either (SaaS Fase 2).
        abort_if($report->agency?->isSuspended() === true, 402, 'Este informe no está disponible en este momento.');

        $domains = $report->definition->embed_domains ?? [];
        $frameAncestors = $domains === [] ? "'none'" : implode(' ', $domains);

        return response()
            ->view('embed', ['token' => $token])
            ->header('Content-Security-Policy', "frame-ancestors {$frameAncestors}");
    }
}
