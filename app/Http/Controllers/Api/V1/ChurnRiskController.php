<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Reports\ChurnRisk;
use Illuminate\Http\JsonResponse;

/**
 * Which clients may be about to cancel, and why (Etapa 5). Internal-only, tenant-scoped.
 */
final class ChurnRiskController extends Controller
{
    public function __invoke(ChurnRisk $risk): JsonResponse
    {
        return response()->json($risk->build());
    }
}
