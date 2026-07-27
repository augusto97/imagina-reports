<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What happens to a subscription AFTER the first payment: monthly renewals (including
 * failed ones), the signed-webhook path, self-service cancellation, and the reconciliation
 * sweep that repairs state when a notification never arrives.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function configureMercadoPago(?string $webhookSecret = null): void
    {
        $settings = PlatformSetting::current();
        $settings->putSecret('mercadopago_access_token', 'TEST-token');
        if ($webhookSecret !== null) {
            $settings->putSecret('mercadopago_webhook_secret', $webhookSecret);
        }
        $settings->save();
    }

    private function subscribedAgency(SubscriptionStatus $status = SubscriptionStatus::Active, string $agencyStatus = 'active'): Agency
    {
        $agency = Agency::factory()->create(['status' => $agencyStatus]);
        Subscription::query()->create([
            'agency_id' => $agency->id,
            'provider' => 'mercadopago',
            'external_id' => 'MP-1',
            'status' => $status,
        ]);

        return $agency;
    }

    /* --------------------------------- Renewals --------------------------------- */

    public function test_a_failed_renewal_charge_marks_the_subscription_past_due(): void
    {
        // MercadoPago keeps the preapproval `authorized` while it retries a failed charge,
        // so the failure is only visible on the authorized payment itself.
        Http::fake([
            'api.mercadopago.com/authorized_payments/AP-1' => Http::response(['preapproval_id' => 'MP-1', 'status' => 'recycling']),
        ]);
        $this->configureMercadoPago();
        $agency = $this->subscribedAgency();

        $this->postJson('/api/v1/webhooks/billing/mercadopago', [
            'type' => 'subscription_authorized_payment',
            'data' => ['id' => 'AP-1'],
        ])->assertOk();

        $subscription = Subscription::query()->where('external_id', 'MP-1')->firstOrFail();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        // The grace window opened: access continues for now.
        $this->assertNotNull($subscription->grace_until);
        $this->assertSame('active', $agency->refresh()->status);
    }

    public function test_a_successful_renewal_refreshes_the_next_charge_date(): void
    {
        Http::fake([
            'api.mercadopago.com/authorized_payments/AP-2' => Http::response(['preapproval_id' => 'MP-1', 'status' => 'processed']),
            'api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'authorized', 'next_payment_date' => '2026-09-01T00:00:00.000-03:00']),
        ]);
        $this->configureMercadoPago();
        $this->subscribedAgency(SubscriptionStatus::PastDue);

        $this->postJson('/api/v1/webhooks/billing/mercadopago', [
            'type' => 'subscription_authorized_payment',
            'data' => ['id' => 'AP-2'],
        ])->assertOk();

        $subscription = Subscription::query()->where('external_id', 'MP-1')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_until);
        $this->assertSame('2026-09-01', $subscription->current_period_end?->toDateString());
    }

    public function test_an_agency_is_suspended_once_the_grace_window_elapses(): void
    {
        $agency = $this->subscribedAgency(SubscriptionStatus::PastDue);
        Subscription::query()->where('agency_id', $agency->id)->update(['grace_until' => now()->subDay()]);

        $this->artisan('billing:enforce-overdue')->assertSuccessful();

        $this->assertSame('suspended', $agency->refresh()->status);
    }

    /* -------------------------------- Signature --------------------------------- */

    public function test_a_webhook_without_a_valid_signature_is_ignored_once_a_secret_is_set(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(['status' => 'authorized'])]);
        $this->configureMercadoPago('super-secret');
        $this->subscribedAgency(SubscriptionStatus::Pending, 'suspended');

        $this->postJson('/api/v1/webhooks/billing/mercadopago', ['type' => 'preapproval', 'data' => ['id' => 'MP-1']])
            ->assertOk();

        // Nothing moved, and we never even asked MercadoPago about it.
        $this->assertSame(SubscriptionStatus::Pending, Subscription::query()->where('external_id', 'MP-1')->firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        Http::fake(['api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'authorized'])]);
        $this->configureMercadoPago('super-secret');
        $agency = $this->subscribedAgency(SubscriptionStatus::Pending, 'suspended');

        $ts = '1700000000';
        $signature = hash_hmac('sha256', 'id:mp-1;request-id:req-1;ts:'.$ts.';', 'super-secret');

        $this->postJson(
            '/api/v1/webhooks/billing/mercadopago',
            ['type' => 'preapproval', 'data' => ['id' => 'MP-1']],
            ['x-signature' => "ts={$ts},v1={$signature}", 'x-request-id' => 'req-1'],
        )->assertOk();

        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->where('external_id', 'MP-1')->firstOrFail()->status);
        $this->assertSame('active', $agency->refresh()->status);
    }

    /* ------------------------------- Cancellation ------------------------------- */

    public function test_cancelling_stops_the_charges_but_keeps_the_paid_period(): void
    {
        Http::fake(['api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'cancelled'])]);
        $this->configureMercadoPago();
        $agency = $this->subscribedAgency();
        Subscription::query()->where('agency_id', $agency->id)->update(['current_period_end' => now()->addDays(20)]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/billing/cancel')->assertOk()->assertJsonStructure(['message', 'access_until']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.mercadopago.com/preapproval/MP-1');

        $subscription = Subscription::query()->where('agency_id', $agency->id)->firstOrFail();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        // Still working: they paid for this month.
        $this->assertSame('active', $agency->refresh()->status);

        // …and access ends when that period does.
        Subscription::query()->where('agency_id', $agency->id)->update(['grace_until' => now()->subMinute()]);
        $this->artisan('billing:enforce-overdue')->assertSuccessful();
        $this->assertSame('suspended', $agency->refresh()->status);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
    }

    public function test_cancelling_without_a_known_paid_period_ends_access_immediately(): void
    {
        Http::fake(['api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'cancelled'])]);
        $this->configureMercadoPago();
        $agency = $this->subscribedAgency();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/billing/cancel')->assertOk();

        $this->assertSame('suspended', $agency->refresh()->status);
    }

    public function test_a_collaborator_cannot_cancel_the_subscription(): void
    {
        $this->configureMercadoPago();
        $agency = $this->subscribedAgency();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Collaborator]));

        $this->postJson('/api/v1/billing/cancel')->assertForbidden();
        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->where('agency_id', $agency->id)->firstOrFail()->status);
    }

    public function test_a_suspended_agency_can_still_cancel(): void
    {
        // Nobody should have to pay to stop paying.
        Http::fake(['api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'cancelled'])]);
        $this->configureMercadoPago();
        $agency = $this->subscribedAgency(SubscriptionStatus::PastDue, 'suspended');
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/billing/cancel')->assertOk();
    }

    /* ------------------------------ Reconciliation ------------------------------ */

    public function test_reconciliation_repairs_state_when_a_webhook_was_never_delivered(): void
    {
        // The agency paid, but the activation notification never reached us.
        Http::fake([
            'api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'authorized', 'next_payment_date' => '2026-10-05T00:00:00.000-03:00']),
        ]);
        $this->configureMercadoPago();
        $agency = $this->subscribedAgency(SubscriptionStatus::Pending, 'suspended');

        $this->artisan('billing:reconcile')->assertSuccessful();

        $subscription = Subscription::query()->where('external_id', 'MP-1')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-10-05', $subscription->current_period_end?->toDateString());
        $this->assertSame('active', $agency->refresh()->status);
    }

    public function test_reconciliation_leaves_an_unchanged_subscription_alone(): void
    {
        Http::fake(['api.mercadopago.com/preapproval/MP-1' => Http::response(['status' => 'authorized'])]);
        $this->configureMercadoPago();
        $this->subscribedAgency();

        $this->assertSame(0, app(BillingService::class)->reconcile());
    }

    public function test_reconciliation_survives_an_unreachable_provider(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(null, 500)]);
        $this->configureMercadoPago();
        $this->subscribedAgency();

        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->where('external_id', 'MP-1')->firstOrFail()->status);
    }
}
