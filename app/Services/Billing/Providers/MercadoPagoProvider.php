<?php

declare(strict_types=1);

namespace App\Services\Billing\Providers;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Billing\BillingException;
use App\Services\Billing\Checkout;
use App\Services\Billing\PaymentProvider;
use App\Services\Billing\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * MercadoPago recurring subscriptions via the Preapproval API (SaaS Fase 2). Uses the
 * platform's access token; charges in each plan's own currency (local per plan). The payer
 * authorizes once at `init_point`, then MercadoPago charges monthly and notifies our webhook.
 */
final class MercadoPagoProvider implements PaymentProvider
{
    private const BASE = 'https://api.mercadopago.com';

    public function key(): string
    {
        return 'mercadopago';
    }

    public function label(): string
    {
        return 'MercadoPago';
    }

    public function isConfigured(PlatformSetting $settings): bool
    {
        return $settings->hasSecret('mercadopago_access_token');
    }

    public function createSubscription(Agency $agency, Plan $plan, PlatformSetting $settings): Checkout
    {
        $token = $settings->secret('mercadopago_access_token');
        if ($token === null) {
            throw new BillingException('MercadoPago no está configurado.');
        }
        if ($plan->monthly_price === null || (float) $plan->monthly_price <= 0) {
            throw new BillingException('El plan no tiene un precio válido.');
        }

        $payload = [
            'reason' => "Plan {$plan->name} · Imagina Reports",
            'external_reference' => 'agency:'.$agency->id,
            'back_url' => $this->appUrl().'/billing/return',
            'status' => 'pending',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $plan->monthly_price,
                'currency_id' => strtoupper($plan->currency),
            ],
        ];

        // Pre-fill the payer's email in production for a smoother checkout. With a TEST
        // token we deliberately DON'T pin it: pinning a real owner email while the seller
        // is a sandbox account makes MercadoPago reject the checkout with "una de las
        // partes es de prueba", so we let the test buyer authenticate freely instead.
        if (! $this->isSandboxToken($token)) {
            $payload['payer_email'] = $this->ownerEmail($agency);
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::BASE.'/preapproval', $payload);

        if ($response->failed()) {
            throw new BillingException('MercadoPago rechazó la suscripción: HTTP '.$response->status());
        }

        $id = $response->json('id');
        $initPoint = $response->json('init_point');

        if (! is_string($id) || ! is_string($initPoint) || $initPoint === '') {
            throw new BillingException('MercadoPago no devolvió un enlace de pago válido.');
        }

        return new Checkout($id, $initPoint);
    }

    public function resolveWebhook(Request $request, PlatformSetting $settings): ?WebhookResult
    {
        $token = $settings->secret('mercadopago_access_token');
        if ($token === null) {
            return null;
        }

        // Only preapproval (subscription) events change access; payment events are ignored.
        $type = $request->input('type', $request->input('topic'));
        if ($type !== 'preapproval' && $type !== 'subscription_preapproval') {
            return null;
        }

        $id = $request->input('data.id', $request->input('id'));
        if (! is_string($id) || $id === '') {
            return null;
        }

        // Source of truth: fetch the preapproval and read its authoritative status.
        $response = Http::withToken($token)->acceptJson()->get(self::BASE.'/preapproval/'.$id);
        if ($response->failed()) {
            return null;
        }

        $raw = $response->json('status');
        $status = is_string($raw) ? $this->mapStatus($raw) : null;
        if ($status === null) {
            return null;
        }

        return new WebhookResult($id, $status);
    }

    private function mapStatus(string $mp): ?SubscriptionStatus
    {
        return match ($mp) {
            'authorized' => SubscriptionStatus::Active,
            'paused' => SubscriptionStatus::Suspended,
            'cancelled' => SubscriptionStatus::Cancelled,
            'pending' => SubscriptionStatus::Pending,
            default => null,
        };
    }

    /**
     * MercadoPago sandbox access tokens are prefixed `TEST-`; production tokens are
     * `APP_USR-…`. We branch on this so test runs don't fight the real-vs-test guard.
     */
    private function isSandboxToken(string $token): bool
    {
        return str_starts_with($token, 'TEST-');
    }

    private function appUrl(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }

    private function ownerEmail(Agency $agency): string
    {
        $email = User::query()->where('agency_id', $agency->id)->where('role', UserRole::Owner->value)->value('email')
            ?? User::query()->where('agency_id', $agency->id)->value('email');

        return is_string($email) ? $email : 'billing@example.com';
    }
}
