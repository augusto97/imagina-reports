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
use App\Services\Billing\Concerns\ParsesProviderDates;
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
    use ParsesProviderDates;

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

    public function createSubscription(Agency $agency, Plan $plan, PlatformSetting $settings, ?string $payerEmail = null): Checkout
    {
        $token = $settings->secret('mercadopago_access_token');
        if ($token === null) {
            throw new BillingException('MercadoPago no está configurado.');
        }
        if ($plan->monthly_price === null || (float) $plan->monthly_price <= 0) {
            throw new BillingException('El plan no tiene un precio válido.');
        }

        // payer_email is REQUIRED by the Preapproval API and MUST match the account the
        // agency authenticates with at checkout — otherwise MercadoPago fails with "tu
        // e-mail no coincide con el de la suscripción". So we use the email the agency
        // supplied (their MercadoPago account, or a TEST buyer in sandbox) and only fall
        // back to the owner's app email when none was given.
        $email = $payerEmail !== null && $payerEmail !== '' ? $payerEmail : $this->ownerEmail($agency);

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::BASE.'/preapproval', [
                'reason' => "Plan {$plan->name} · Imagina Reports",
                'external_reference' => 'agency:'.$agency->id,
                'payer_email' => $email,
                'back_url' => $this->appUrl().'/billing/return',
                'status' => 'pending',
                'auto_recurring' => [
                    'frequency' => 1,
                    'frequency_type' => 'months',
                    'transaction_amount' => (float) $plan->monthly_price,
                    'currency_id' => strtoupper($plan->currency),
                ],
            ]);

        if ($response->failed()) {
            throw new BillingException('MercadoPago rechazó la suscripción: '.$this->errorReason($response->json(), $response->status()));
        }

        $id = $response->json('id');
        $initPoint = $response->json('init_point');

        if (! is_string($id) || ! is_string($initPoint) || $initPoint === '') {
            throw new BillingException('MercadoPago no devolvió un enlace de pago válido.');
        }

        return new Checkout($id, $initPoint);
    }

    public function cancelSubscription(string $externalId, PlatformSetting $settings): void
    {
        $token = $settings->secret('mercadopago_access_token');
        if ($token === null) {
            throw new BillingException('MercadoPago no está configurado.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->put(self::BASE.'/preapproval/'.$externalId, ['status' => 'cancelled']);

        if ($response->failed()) {
            throw new BillingException('MercadoPago no pudo cancelar la suscripción anterior: '.$this->errorReason($response->json(), $response->status()));
        }
    }

    public function resolveWebhook(Request $request, PlatformSetting $settings): ?WebhookResult
    {
        $token = $settings->secret('mercadopago_access_token');
        if ($token === null) {
            return null;
        }

        $type = $request->input('type', $request->input('topic'));
        $id = $this->notificationId($request);

        if (! is_string($type) || $id === '' || ! $this->signatureIsValid($request, $settings, $id)) {
            return null;
        }

        // A renewal charge. Here the id is the authorized PAYMENT, not the preapproval.
        if ($type === 'subscription_authorized_payment' || $type === 'authorized_payment') {
            return $this->resolveAuthorizedPayment($id, $token, $settings);
        }

        if ($type !== 'preapproval' && $type !== 'subscription_preapproval') {
            return null;
        }

        return $this->fetchStatus($id, $settings);
    }

    /** Source of truth: MercadoPago's own record of the preapproval, never the payload. */
    public function fetchStatus(string $externalId, PlatformSetting $settings): ?WebhookResult
    {
        $token = $settings->secret('mercadopago_access_token');
        if ($token === null) {
            return null;
        }

        $response = Http::withToken($token)->acceptJson()->get(self::BASE.'/preapproval/'.$externalId);
        if ($response->failed()) {
            return null;
        }

        $raw = $response->json('status');
        $status = is_string($raw) ? $this->mapStatus($raw) : null;
        if ($status === null) {
            return null;
        }

        return new WebhookResult($externalId, $status, $this->parseProviderDate($response->json('next_payment_date')));
    }

    /**
     * Resolve a recurring-charge notification.
     *
     * A failed renewal does NOT change the preapproval's status — MercadoPago keeps it
     * `authorized` while it retries. Without reading the authorized payment the app would
     * never learn a charge failed, so the grace window would never start and the agency
     * would be cut off abruptly whenever MercadoPago finally paused the subscription.
     */
    private function resolveAuthorizedPayment(string $paymentId, string $token, PlatformSetting $settings): ?WebhookResult
    {
        $response = Http::withToken($token)->acceptJson()->get(self::BASE.'/authorized_payments/'.$paymentId);
        if ($response->failed()) {
            return null;
        }

        $preapprovalId = $response->json('preapproval_id');
        if (! is_string($preapprovalId) || $preapprovalId === '') {
            return null;
        }

        // `recycling` = retrying after a failure; `rejected` = the charge was refused.
        $paymentStatus = $response->json('status');
        if ($paymentStatus === 'recycling' || $paymentStatus === 'rejected') {
            return new WebhookResult($preapprovalId, SubscriptionStatus::PastDue);
        }

        // Charged fine: re-read the preapproval so the next charge date is refreshed too.
        return $this->fetchStatus($preapprovalId, $settings);
    }

    /** The notification's subject id, which MercadoPago sends as a string or a number. */
    private function notificationId(Request $request): string
    {
        $raw = $request->input('data.id', $request->input('id'));

        if (is_string($raw)) {
            return $raw;
        }

        return is_int($raw) ? (string) $raw : '';
    }

    /**
     * Verify MercadoPago's `x-signature` HMAC.
     *
     * Skipped when no webhook secret is configured: forging is already pointless here —
     * every status comes from a direct call to MercadoPago, never from the payload — and
     * failing closed would silently stop renewals on every install that hasn't pasted the
     * secret yet. Once the secret IS set this fails CLOSED, which also shuts the door on
     * unauthenticated traffic making us call MercadoPago's API.
     */
    private function signatureIsValid(Request $request, PlatformSetting $settings, string $id): bool
    {
        $secret = $settings->secret('mercadopago_webhook_secret');
        if ($secret === null) {
            return true;
        }

        $header = $request->header('x-signature');
        if (! is_string($header) || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($ts === null || $v1 === null) {
            return false;
        }

        // Manifest template documented by MercadoPago: id, request-id and ts, each ending
        // in ';', omitting the parts the notification didn't carry.
        $manifest = 'id:'.strtolower($id).';';
        $requestId = $request->header('x-request-id');
        if (is_string($requestId) && $requestId !== '') {
            $manifest .= 'request-id:'.$requestId.';';
        }
        $manifest .= 'ts:'.$ts.';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $v1);
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
     * Pull MercadoPago's human-readable reason out of an error body so the agency sees
     * *why* the checkout was refused (e.g. "collector and payer cannot be the same",
     * "invalid currency") instead of a bare HTTP code. Falls back to the status code.
     *
     * @param  mixed  $body
     */
    private function errorReason($body, int $status): string
    {
        if (is_array($body)) {
            // MP returns `message`, sometimes with per-field detail under `cause`.
            $message = is_string($body['message'] ?? null) ? $body['message'] : null;

            $causes = [];
            if (is_array($body['cause'] ?? null)) {
                foreach ($body['cause'] as $cause) {
                    $description = is_array($cause) ? ($cause['description'] ?? null) : null;
                    if (is_string($description) && $description !== '') {
                        $causes[] = $description;
                    }
                }
            }

            $parts = array_filter([$message, $causes === [] ? null : implode('; ', $causes)]);
            if ($parts !== []) {
                return implode(' — ', $parts);
            }
        }

        return 'HTTP '.$status;
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
