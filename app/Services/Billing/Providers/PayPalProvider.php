<?php

declare(strict_types=1);

namespace App\Services\Billing\Providers;

use App\Enums\SubscriptionStatus;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Services\Billing\BillingException;
use App\Services\Billing\Checkout;
use App\Services\Billing\PaymentProvider;
use App\Services\Billing\WebhookResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayPal recurring subscriptions via the Subscriptions API (SaaS Fase 2). Provisions a
 * product + billing plan on demand (cached in platform settings) for each plan/currency,
 * then creates a subscription and returns the approval link. Sandbox/live via a toggle.
 */
final class PayPalProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function isConfigured(PlatformSetting $settings): bool
    {
        return $settings->hasSecret('paypal_client_id') && $settings->hasSecret('paypal_secret');
    }

    private function base(PlatformSetting $settings): string
    {
        return $settings->get('billing_sandbox') === false ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /** An authenticated PayPal client (OAuth2 client-credentials). */
    private function client(PlatformSetting $settings): PendingRequest
    {
        $id = $settings->secret('paypal_client_id');
        $secret = $settings->secret('paypal_secret');
        if ($id === null || $secret === null) {
            throw new BillingException('PayPal no está configurado.');
        }

        $token = Http::asForm()->withBasicAuth($id, $secret)
            ->post($this->base($settings).'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new BillingException('No se pudo autenticar con PayPal.');
        }

        return Http::withToken($token)->acceptJson()->baseUrl($this->base($settings));
    }

    public function createSubscription(Agency $agency, Plan $plan, PlatformSetting $settings): Checkout
    {
        if ($plan->monthly_price === null || (float) $plan->monthly_price <= 0) {
            throw new BillingException('El plan no tiene un precio válido.');
        }

        $client = $this->client($settings);
        $planId = $this->ensurePlan($client, $plan, $settings);

        $response = $client->post('/v1/billing/subscriptions', [
            'plan_id' => $planId,
            'custom_id' => 'agency:'.$agency->id,
            'application_context' => [
                'brand_name' => 'Imagina Reports',
                'return_url' => $this->appUrl().'/billing/return',
                'cancel_url' => $this->appUrl().'/billing/cancel',
            ],
        ]);

        if ($response->failed()) {
            throw new BillingException('PayPal rechazó la suscripción: HTTP '.$response->status());
        }

        $id = $response->json('id');
        $approve = null;
        $links = $response->json('links');
        foreach (is_array($links) ? $links : [] as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'approve' && is_string($link['href'] ?? null)) {
                $approve = $link['href'];
            }
        }

        if (! is_string($id) || ! is_string($approve)) {
            throw new BillingException('PayPal no devolvió un enlace de aprobación.');
        }

        return new Checkout($id, $approve);
    }

    /** Provision (and cache) a PayPal billing plan for this plan + price + currency. */
    private function ensurePlan(PendingRequest $client, Plan $plan, PlatformSetting $settings): string
    {
        $cacheKey = sprintf('paypal_plan:%d:%s:%s', $plan->id, strtoupper($plan->currency), (string) $plan->monthly_price);
        $cached = $settings->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // A product is required to hang plans off; reuse one across all plans.
        $productId = $settings->get('paypal_product_id');
        if (! is_string($productId) || $productId === '') {
            $productId = $client->post('/v1/catalogs/products', ['name' => 'Imagina Reports', 'type' => 'SERVICE'])->json('id');
            if (! is_string($productId)) {
                throw new BillingException('No se pudo crear el producto en PayPal.');
            }
            $settings->put('paypal_product_id', $productId);
        }

        $planId = $client->post('/v1/billing/plans', [
            'product_id' => $productId,
            'name' => "Plan {$plan->name}",
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => (string) $plan->monthly_price, 'currency_code' => strtoupper($plan->currency)]],
            ]],
            'payment_preferences' => ['auto_bill_outstanding' => true],
        ])->json('id');

        if (! is_string($planId)) {
            throw new BillingException('No se pudo crear el plan en PayPal.');
        }

        $settings->put($cacheKey, $planId);
        $settings->save();

        return $planId;
    }

    private function appUrl(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }

    public function resolveWebhook(Request $request, PlatformSetting $settings): ?WebhookResult
    {
        $event = $request->input('event_type');
        $id = $request->input('resource.id');

        if (! is_string($event) || ! is_string($id) || $id === '') {
            return null;
        }

        $status = match ($event) {
            'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.RE-ACTIVATED', 'PAYMENT.SALE.COMPLETED' => SubscriptionStatus::Active,
            'BILLING.SUBSCRIPTION.SUSPENDED' => SubscriptionStatus::Suspended,
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED' => SubscriptionStatus::Cancelled,
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => SubscriptionStatus::PastDue,
            default => null,
        };

        return $status !== null ? new WebhookResult($id, $status) : null;
    }
}
