<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Subscription;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agency-facing billing (SaaS Fase 2, self-service): see the current subscription + the
 * available payment methods, and start a subscription for the agency's assigned plan.
 */
final class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly TenantContext $tenant) {}

    public function show(): JsonResponse
    {
        $agency = Agency::query()->with('plan')->findOrFail($this->tenant->id());
        $subscription = Subscription::query()->where('agency_id', $agency->id)->latest()->first();

        return response()->json([
            'status' => $agency->status,
            'plan' => $agency->plan !== null
                ? ['name' => $agency->plan->name, 'monthly_price' => $agency->plan->monthly_price !== null ? (float) $agency->plan->monthly_price : null, 'currency' => $agency->plan->currency]
                : null,
            'subscription' => $subscription !== null ? [
                'provider' => $subscription->provider,
                'status' => $subscription->status->value,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
            'providers' => array_map(
                static fn ($p): array => ['key' => $p->key(), 'label' => $p->label()],
                $this->billing->availableProviders(),
            ),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate(['provider' => ['required', 'string']]);

        $agency = Agency::query()->with('plan')->findOrFail($this->tenant->id());
        if ($agency->plan === null) {
            return response()->json(['message' => 'Tu cuenta aún no tiene un plan asignado. Contacta con soporte.'], 422);
        }

        try {
            $url = $this->billing->subscribe($agency, $agency->plan, $request->string('provider')->toString());
        } catch (BillingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['approval_url' => $url]);
    }
}
