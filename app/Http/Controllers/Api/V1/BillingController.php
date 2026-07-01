<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
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

        $ownerEmail = User::query()->where('agency_id', $agency->id)->where('role', UserRole::Owner->value)->value('email')
            ?? User::query()->where('agency_id', $agency->id)->value('email');

        return response()->json([
            'status' => $agency->status,
            'current_plan_id' => $agency->plan_id,
            // Suggested email to pay with (MercadoPago must match it at checkout); editable.
            'billing_email' => is_string($ownerEmail) ? $ownerEmail : null,
            'plan' => $agency->plan !== null
                ? ['name' => $agency->plan->name, 'monthly_price' => $agency->plan->monthly_price !== null ? (float) $agency->plan->monthly_price : null, 'currency' => $agency->plan->currency]
                : null,
            'subscription' => $subscription !== null ? [
                'provider' => $subscription->provider,
                'plan_id' => $subscription->plan_id,
                'status' => $subscription->status->value,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
            // The plans the agency can subscribe to (self-service).
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()->map(static fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'monthly_price' => $plan->monthly_price !== null ? (float) $plan->monthly_price : null,
                'currency' => $plan->currency,
                'max_sites' => $plan->max_sites,
                'max_clients' => $plan->max_clients,
                'max_users' => $plan->max_users,
                'features' => $plan->features ?? [],
            ])->all(),
            'providers' => array_map(
                static fn ($p): array => ['key' => $p->key(), 'label' => $p->label()],
                $this->billing->availableProviders(),
            ),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string'],
            'plan_id' => ['required', 'integer'],
            // The email of the account the agency pays with (MercadoPago requires it to
            // match what they authenticate with at checkout). Optional; defaults to owner.
            'payer_email' => ['nullable', 'email'],
        ]);

        $agency = Agency::query()->findOrFail($this->tenant->id());
        $plan = Plan::query()->where('is_active', true)->find($request->integer('plan_id'));

        if ($plan === null) {
            return response()->json(['message' => 'El plan elegido no está disponible.'], 422);
        }
        if ($plan->monthly_price === null || (float) $plan->monthly_price <= 0) {
            return response()->json(['message' => 'Este plan no es de pago; contacta con soporte para activarlo.'], 422);
        }

        $payerEmail = $request->string('payer_email')->toString();

        try {
            $url = $this->billing->subscribe($agency, $plan, $request->string('provider')->toString(), $payerEmail === '' ? null : $payerEmail);
        } catch (BillingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['approval_url' => $url]);
    }
}
