<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Site;
use App\Reports\AiReportBuilder;
use App\Reports\AiReportException;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Generate with AI" (CLAUDE.md §10.6/§11.1): assembles a draft block layout for a
 * site from its metric catalog + an optional prompt, validated against the catalog.
 * The admin opens the result in the editor.
 */
final class AiTemplateController extends Controller
{
    public function store(Request $request, Site $site, AiReportBuilder $builder, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($entitlements->hasFeature($agency, 'ai_builder'), 403, 'Tu plan no incluye el generador con IA. Mejora el plan para usarlo.');

        $prompt = $request->string('prompt')->toString();

        try {
            $result = $builder->assembleTemplate($site, $prompt === '' ? null : $prompt);
        } catch (AiReportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }
}
