<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Services\Billing\Providers\MercadoPagoProvider;
use App\Services\Billing\Providers\PayPalProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Orchestrates subscriptions (SaaS Fase 2): start a checkout, react to provider webhooks,
 * and keep the agency's active/suspended status in sync with its subscription. Providers
 * are pluggable (MercadoPago, PayPal).
 */
final class BillingService
{
    private const GRACE_DAYS = 7;

    public function __construct(
        private readonly MercadoPagoProvider $mercadoPago,
        private readonly PayPalProvider $payPal,
    ) {}

    /**
     * @return array<string, PaymentProvider>
     */
    public function providers(): array
    {
        return [$this->mercadoPago->key() => $this->mercadoPago, $this->payPal->key() => $this->payPal];
    }

    public function provider(string $key): ?PaymentProvider
    {
        return $this->providers()[$key] ?? null;
    }

    /**
     * The providers the platform has configured (so the agency only sees usable options).
     *
     * @return list<PaymentProvider>
     */
    public function availableProviders(): array
    {
        $settings = PlatformSetting::current();

        return array_values(array_filter($this->providers(), static fn (PaymentProvider $p): bool => $p->isConfigured($settings)));
    }

    /**
     * Start a subscription; returns the approval URL to send the agency owner to.
     *
     * @throws BillingException
     */
    public function subscribe(Agency $agency, Plan $plan, string $providerKey, ?string $payerEmail = null): string
    {
        $provider = $this->provider($providerKey);
        $settings = PlatformSetting::current();

        if ($provider === null || ! $provider->isConfigured($settings)) {
            throw new BillingException('El método de pago no está disponible.');
        }

        $checkout = $provider->createSubscription($agency, $plan, $settings, $payerEmail);

        Subscription::query()->updateOrCreate(
            ['agency_id' => $agency->id],
            [
                'plan_id' => $plan->id,
                'provider' => $provider->key(),
                'external_id' => $checkout->externalId,
                'status' => SubscriptionStatus::Pending,
                'grace_until' => null,
            ],
        );

        return $checkout->approvalUrl;
    }

    /** Process an inbound provider webhook; returns true when a subscription was updated. */
    public function handleWebhook(string $providerKey, Request $request): bool
    {
        $provider = $this->provider($providerKey);
        if ($provider === null) {
            return false;
        }

        $result = $provider->resolveWebhook($request, PlatformSetting::current());
        if ($result === null) {
            return false;
        }

        $subscription = Subscription::query()
            ->where('provider', $provider->key())
            ->where('external_id', $result->externalId)
            ->first();

        if ($subscription === null) {
            return false;
        }

        $this->applyStatus($subscription, $result->status);

        return true;
    }

    /** Move a subscription to a new status and sync the agency's access accordingly. */
    public function applyStatus(Subscription $subscription, SubscriptionStatus $status): void
    {
        $subscription->status = $status;
        $subscription->grace_until = $status === SubscriptionStatus::PastDue
            ? Date::now()->addDays(self::GRACE_DAYS)
            : null;
        $subscription->save();

        $agency = $subscription->agency;
        if ($agency !== null) {
            $agency->status = $status->grantsAccess() ? 'active' : 'suspended';
            // On activation, apply the subscribed plan (self-service: the agency's plan
            // is only granted once the payment is authorized).
            if ($status === SubscriptionStatus::Active && $subscription->plan_id !== null) {
                $agency->plan_id = $subscription->plan_id;
            }
            $agency->save();
        }
    }

    /**
     * Suspend agencies whose grace window elapsed (PastDue past grace_until). Called from the
     * scheduler so a failed payment eventually cuts access even without a follow-up webhook.
     *
     * @return int number of agencies suspended
     */
    public function enforceOverdue(): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', Date::now())
            ->with('agency')
            ->each(function (Subscription $subscription) use (&$count): void {
                $this->applyStatus($subscription, SubscriptionStatus::Suspended);
                $count++;
            });

        return $count;
    }
}
