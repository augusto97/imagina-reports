<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public payment-provider webhooks (SaaS Fase 2). No auth (the request comes from
 * MercadoPago/PayPal); the provider adapter verifies authenticity and fetches the
 * authoritative status. Always returns 200 so providers don't spam retries.
 */
final class BillingWebhookController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $this->billing->handleWebhook($provider, $request);

        return response()->json(['received' => true]);
    }
}
