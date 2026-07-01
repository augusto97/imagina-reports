<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Agency;
use App\Models\Plan;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;

/**
 * A recurring-payment provider (SaaS Fase 2). Behind an interface so MercadoPago, PayPal
 * (and future providers) are interchangeable. Credentials come from the platform settings.
 */
interface PaymentProvider
{
    public function key(): string;

    public function label(): string;

    /** Whether the platform has configured this provider's credentials. */
    public function isConfigured(PlatformSetting $settings): bool;

    /**
     * Start a subscription for the agency on the given plan; returns the id + approval URL.
     * $payerEmail, when given, is the email of the account the agency will pay with (e.g.
     * their MercadoPago account) — it must match what they authenticate with at checkout.
     *
     * @throws BillingException
     */
    public function createSubscription(Agency $agency, Plan $plan, PlatformSetting $settings, ?string $payerEmail = null): Checkout;

    /**
     * Parse an inbound webhook into a normalized status change, or null when it's not a
     * subscription event we act on. Implementations verify authenticity against the settings.
     */
    public function resolveWebhook(Request $request, PlatformSetting $settings): ?WebhookResult;
}
