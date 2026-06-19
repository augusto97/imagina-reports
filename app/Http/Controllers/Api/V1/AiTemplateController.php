<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Reports\AiReportBuilder;
use App\Reports\AiReportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Generate with AI" (CLAUDE.md §10.6/§11.1): assembles a draft block layout for a
 * site from its metric catalog + an optional prompt, validated against the catalog.
 * The admin opens the result in the editor.
 */
final class AiTemplateController extends Controller
{
    public function store(Request $request, Site $site, AiReportBuilder $builder): JsonResponse
    {
        $prompt = $request->string('prompt')->toString();

        try {
            $result = $builder->assembleTemplate($site, $prompt === '' ? null : $prompt);
        } catch (AiReportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }
}
