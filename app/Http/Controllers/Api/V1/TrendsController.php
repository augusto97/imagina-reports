<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Reports\AgencyTrends;
use Illuminate\Http\JsonResponse;

/**
 * Agency-wide trends + multi-client comparisons (CLAUDE.md §13). Aggregates frozen
 * report data for the authenticated agency — no live API calls.
 */
final class TrendsController extends Controller
{
    public function __construct(private readonly AgencyTrends $trends) {}

    public function index(): JsonResponse
    {
        return response()->json($this->trends->build());
    }
}
