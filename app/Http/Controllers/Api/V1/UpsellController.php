<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Reports\AgencyUpsell;
use Illuminate\Http\JsonResponse;

/**
 * Agency-wide upsell opportunities (CLAUDE.md §13). Re-evaluates the upsell signals
 * from each site's most recent frozen report — no live API calls. Internal-only.
 */
final class UpsellController extends Controller
{
    public function __construct(private readonly AgencyUpsell $upsell) {}

    public function index(): JsonResponse
    {
        return response()->json($this->upsell->build());
    }
}
