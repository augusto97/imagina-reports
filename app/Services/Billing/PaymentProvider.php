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
     *
     * @throws BillingException
     */
    public function createSubscription(Agency $agency, Plan $plan, PlatformSetting $settings): Checkout;

    /**
     * Parse an inbound webhook into a normalized status change, or null when it's not a
     * subscription event we act on. Implementations verify authenticity against the settings.
     */
    public function resolveWebhook(Request $request, PlatformSetting $settings): ?WebhookResult;
}
