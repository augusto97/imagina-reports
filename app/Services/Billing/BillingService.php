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
use Illuminate\Support\Carbon;
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

        // If a live subscription is being replaced (plan change / provider change), remember
        // it: the provider keeps charging the OLD one until someone cancels it. We cancel it
        // when the NEW one activates (see applyStatus) — never before, so an abandoned
        // checkout doesn't leave the agency without its current subscription.
        $existing = Subscription::query()->where('agency_id', $agency->id)->first();
        $replaces = null;

        if (
            $existing !== null
            && is_string($existing->external_id) && $existing->external_id !== ''
            && $existing->external_id !== $checkout->externalId
            && in_array($existing->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
        ) {
            $replaces = ['provider' => $existing->provider, 'external_id' => $existing->external_id];
        }

        Subscription::query()->updateOrCreate(
            ['agency_id' => $agency->id],
            [
                'plan_id' => $plan->id,
                'provider' => $provider->key(),
                'external_id' => $checkout->externalId,
                'status' => SubscriptionStatus::Pending,
                'grace_until' => null,
                'meta' => $replaces !== null ? ['replaces' => $replaces] : null,
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

        $this->applyStatus($subscription, $result->status, $result->currentPeriodEnd);

        return true;
    }

    /**
     * Cancel the agency's own subscription at the provider.
     *
     * The agency keeps the access it already paid for: cancelling stops future charges and
     * schedules the cut-off for the end of the current period (enforced daily by
     * billing:enforce-overdue). Only when there's no known paid period left does access end
     * immediately — charging someone for a month and locking them out the same day is wrong.
     *
     * @throws BillingException
     */
    public function cancel(Agency $agency): void
    {
        $subscription = Subscription::query()->where('agency_id', $agency->id)->first();

        if ($subscription === null || ! is_string($subscription->external_id) || $subscription->external_id === '') {
            throw new BillingException('No hay una suscripción que cancelar.');
        }

        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw new BillingException('Esta suscripción ya está cancelada.');
        }

        $provider = $this->provider($subscription->provider);
        if ($provider === null) {
            throw new BillingException('El método de pago de esta suscripción ya no está disponible.');
        }

        $provider->cancelSubscription($subscription->external_id, PlatformSetting::current());

        $paidUntil = $subscription->current_period_end;
        $keepsAccess = $paidUntil !== null && $paidUntil->isFuture();

        $subscription->status = SubscriptionStatus::Cancelled;
        $subscription->grace_until = $keepsAccess ? $paidUntil : null;
        $subscription->save();

        if (! $keepsAccess) {
            $agency->status = 'suspended';
            $agency->save();
        }
    }

    /** Move a subscription to a new status and sync the agency's access accordingly. */
    public function applyStatus(Subscription $subscription, SubscriptionStatus $status, ?Carbon $currentPeriodEnd = null): void
    {
        $subscription->status = $status;
        $subscription->grace_until = $status === SubscriptionStatus::PastDue
            ? Date::now()->addDays(self::GRACE_DAYS)
            : null;
        // Providers only tell us the next charge date sometimes; keep the last known one.
        if ($currentPeriodEnd !== null) {
            $subscription->current_period_end = $currentPeriodEnd;
        }
        $subscription->save();

        // A `pending` notification just means a checkout was created/not yet authorized —
        // it must NOT touch the agency's access. Otherwise an active agency that starts
        // (and maybe abandons) an upgrade checkout would be suspended by its own attempt.
        if ($status === SubscriptionStatus::Pending) {
            return;
        }

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

        if ($status === SubscriptionStatus::Active) {
            $this->cancelReplacedSubscription($subscription);
        }
    }

    /**
     * Once a replacement subscription activates, cancel the one it replaced at the provider
     * so the agency isn't double-charged. Best-effort: a provider hiccup is reported (for
     * manual follow-up) but never blocks the activation itself.
     */
    private function cancelReplacedSubscription(Subscription $subscription): void
    {
        $meta = $subscription->meta ?? [];
        $replaces = $meta['replaces'] ?? null;

        if (! is_array($replaces)) {
            return;
        }

        $providerKey = $replaces['provider'] ?? null;
        $externalId = $replaces['external_id'] ?? null;

        if (is_string($providerKey) && is_string($externalId) && $externalId !== '' && $externalId !== $subscription->external_id) {
            try {
                $this->provider($providerKey)?->cancelSubscription($externalId, PlatformSetting::current());
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        unset($meta['replaces']);
        $subscription->meta = $meta === [] ? null : $meta;
        $subscription->save();
    }

    /**
     * Cut access once a grace window elapsed. Two cases share this: a payment that stayed
     * overdue past the grace days, and a cancelled subscription whose already-paid period
     * has now ended. Called from the scheduler so both eventually take effect without
     * depending on a follow-up webhook.
     *
     * @return int number of agencies suspended
     */
    public function enforceOverdue(): int
    {
        $count = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Cancelled->value])
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', Date::now())
            ->with('agency')
            ->each(function (Subscription $subscription) use (&$count): void {
                if ($subscription->status === SubscriptionStatus::Cancelled) {
                    // Keep it recorded as cancelled — that's WHY access ended.
                    $subscription->grace_until = null;
                    $subscription->save();

                    $agency = $subscription->agency;
                    if ($agency !== null) {
                        $agency->status = 'suspended';
                        $agency->save();
                    }
                } else {
                    $this->applyStatus($subscription, SubscriptionStatus::Suspended);
                }

                $count++;
            });

        return $count;
    }

    /**
     * Ask each provider for the real state of the subscriptions we believe are live, and
     * apply anything that drifted. Webhooks get lost — a delivery failure, a deploy mid-
     * notification, a provider outage — and without this the app would keep charging-free
     * agencies active (or paying ones suspended) indefinitely.
     *
     * @return int number of subscriptions whose state was corrected
     */
    public function reconcile(): int
    {
        $settings = PlatformSetting::current();
        $changed = 0;

        Subscription::query()
            ->whereIn('status', [
                SubscriptionStatus::Pending->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->whereNotNull('external_id')
            ->with('agency')
            ->each(function (Subscription $subscription) use ($settings, &$changed): void {
                $externalId = $subscription->external_id;
                $provider = $this->provider($subscription->provider);

                if ($provider === null || ! is_string($externalId) || $externalId === '') {
                    return;
                }

                try {
                    $result = $provider->fetchStatus($externalId, $settings);
                } catch (\Throwable $exception) {
                    // One unreachable provider must not stop the rest of the sweep.
                    report($exception);

                    return;
                }

                if ($result === null) {
                    return;
                }

                $sameStatus = $result->status === $subscription->status;
                $samePeriod = $result->currentPeriodEnd === null
                    || $subscription->current_period_end?->equalTo($result->currentPeriodEnd) === true;

                if ($sameStatus && $samePeriod) {
                    return;
                }

                $this->applyStatus($subscription, $result->status, $result->currentPeriodEnd);
                $changed++;
            });

        return $changed;
    }
}
