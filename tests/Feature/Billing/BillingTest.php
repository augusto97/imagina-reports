<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function configureMercadoPago(): void
    {
        $settings = PlatformSetting::current();
        $settings->putSecret('mercadopago_access_token', 'TEST-token');
        $settings->save();
    }

    private function agencyWithPlan(): Agency
    {
        $plan = Plan::factory()->create(['monthly_price' => 49, 'currency' => 'ARS']);
        $agency = Agency::factory()->create(['plan_id' => $plan->id, 'status' => 'active']);
        User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]);

        return $agency->load('plan');
    }

    public function test_agency_starts_a_mercadopago_subscription(): void
    {
        Http::fake([
            'api.mercadopago.com/preapproval' => Http::response(['id' => 'MP-123', 'init_point' => 'https://mp.test/authorize/MP-123']),
        ]);
        $this->configureMercadoPago();
        $agency = $this->agencyWithPlan();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/billing/subscribe', ['provider' => 'mercadopago', 'plan_id' => $agency->plan_id])
            ->assertOk()
            ->assertJsonPath('approval_url', 'https://mp.test/authorize/MP-123');

        $this->assertDatabaseHas('ir_subscriptions', ['agency_id' => $agency->id, 'provider' => 'mercadopago', 'external_id' => 'MP-123', 'status' => 'pending']);
    }

    public function test_billing_lists_the_plans_the_agency_can_choose(): void
    {
        $agency = $this->agencyWithPlan();
        Plan::factory()->create(['name' => 'Otro', 'is_active' => true, 'monthly_price' => 99]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonCount(2, 'plans');
    }

    public function test_subscribe_shows_only_configured_providers(): void
    {
        $agency = $this->agencyWithPlan();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        // Nothing configured → no providers offered.
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonCount(0, 'providers');

        $this->configureMercadoPago();
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('providers.0.key', 'mercadopago');
    }

    public function test_webhook_activates_the_subscription_and_agency(): void
    {
        Http::fake(['api.mercadopago.com/preapproval/MP-9' => Http::response(['id' => 'MP-9', 'status' => 'authorized'])]);
        $this->configureMercadoPago();

        $agency = Agency::factory()->create(['status' => 'suspended']);
        Subscription::query()->create(['agency_id' => $agency->id, 'provider' => 'mercadopago', 'external_id' => 'MP-9', 'status' => SubscriptionStatus::Pending]);

        $this->postJson('/api/v1/webhooks/billing/mercadopago', ['type' => 'preapproval', 'data' => ['id' => 'MP-9']])
            ->assertOk();

        $this->assertDatabaseHas('ir_subscriptions', ['external_id' => 'MP-9', 'status' => 'active']);
        $this->assertSame('active', $agency->refresh()->status);
    }

    public function test_a_cancelled_webhook_suspends_the_agency(): void
    {
        Http::fake(['api.mercadopago.com/preapproval/MP-x' => Http::response(['id' => 'MP-x', 'status' => 'cancelled'])]);
        $this->configureMercadoPago();

        $agency = Agency::factory()->create(['status' => 'active']);
        Subscription::query()->create(['agency_id' => $agency->id, 'provider' => 'mercadopago', 'external_id' => 'MP-x', 'status' => SubscriptionStatus::Active]);

        $this->postJson('/api/v1/webhooks/billing/mercadopago', ['type' => 'preapproval', 'data' => ['id' => 'MP-x']])->assertOk();

        $this->assertSame('suspended', $agency->refresh()->status);
    }

    public function test_a_suspended_agency_is_blocked_but_can_reach_billing(): void
    {
        $plan = Plan::factory()->create();
        $agency = Agency::factory()->create(['plan_id' => $plan->id, 'status' => 'suspended']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->getJson('/api/v1/clients')->assertStatus(402);
        $this->getJson('/api/v1/billing')->assertOk();
    }

    public function test_enforce_overdue_suspends_after_the_grace_window(): void
    {
        $agency = Agency::factory()->create(['status' => 'active']);
        Subscription::query()->create([
            'agency_id' => $agency->id, 'provider' => 'mercadopago', 'external_id' => 'MP-late',
            'status' => SubscriptionStatus::PastDue, 'grace_until' => now()->subDay(),
        ]);

        $suspended = app(BillingService::class)->enforceOverdue();

        $this->assertSame(1, $suspended);
        $this->assertSame('suspended', $agency->refresh()->status);
    }

    public function test_platform_admin_saves_credentials_without_leaking_them(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true]));

        $this->putJson('/api/v1/platform/billing-settings', ['mercadopago_access_token' => 'secret-token'])
            ->assertOk()
            ->assertJsonPath('mercadopago_configured', true)
            ->assertJsonMissing(['mercadopago_access_token' => 'secret-token']);

        $this->assertNotSame('secret-token', PlatformSetting::current()->settings['mercadopago_access_token'] ?? null);
        $this->assertSame('secret-token', PlatformSetting::current()->secret('mercadopago_access_token'));
    }
}
