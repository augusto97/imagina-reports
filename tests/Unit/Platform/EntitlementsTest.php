<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function entitlements(): Entitlements
    {
        return app(Entitlements::class);
    }

    public function test_limits_come_from_the_plan(): void
    {
        $plan = Plan::factory()->create(['max_sites' => 3, 'features' => ['ai_builder' => true]]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id]);

        $limits = $this->entitlements()->limits($agency);

        $this->assertSame(3, $limits['max_sites']);
        $this->assertTrue($limits['features']['ai_builder']);
    }

    public function test_overrides_win_over_the_plan(): void
    {
        $plan = Plan::factory()->create(['max_sites' => 3]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id, 'plan_overrides' => ['max_sites' => 99]]);

        $this->assertSame(99, $this->entitlements()->limits($agency)['max_sites']);
    }

    public function test_can_add_site_respects_the_limit(): void
    {
        $plan = Plan::factory()->create(['max_sites' => 1]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id]);
        $client = Client::factory()->create(['agency_id' => $agency->id]);

        $this->assertTrue($this->entitlements()->canAddSite($agency));

        Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        $this->assertFalse($this->entitlements()->canAddSite($agency->refresh()));
    }

    public function test_null_limit_is_unlimited(): void
    {
        $plan = Plan::factory()->unlimited()->create();
        $agency = Agency::factory()->create(['plan_id' => $plan->id]);
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        Site::factory()->count(5)->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        $this->assertTrue($this->entitlements()->canAddSite($agency));
    }

    public function test_allowed_connectors_whitelist(): void
    {
        $plan = Plan::factory()->create(['allowed_connectors' => ['mainwp', 'ga4']]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id]);

        $this->assertTrue($this->entitlements()->allowsConnector($agency, 'ga4'));
        $this->assertFalse($this->entitlements()->allowsConnector($agency, 'woocommerce'));
    }

    public function test_feature_is_off_when_the_plan_disables_it(): void
    {
        $plan = Plan::factory()->create(['features' => ['ai_builder' => false]]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id]);

        $this->assertFalse($this->entitlements()->hasFeature($agency, 'ai_builder'));
    }

    public function test_no_plan_grants_nothing(): void
    {
        // The core fix: a plan-less agency is restricted (0), never unlimited.
        $agency = Agency::factory()->create(['plan_id' => null]);
        $client = Client::factory()->create(['agency_id' => $agency->id]);

        $this->assertFalse($this->entitlements()->hasFeature($agency, 'ai_builder'));
        $this->assertFalse($this->entitlements()->canAddSite($agency));
        $this->assertFalse($this->entitlements()->canAddClient($agency));
        $this->assertSame(0, $this->entitlements()->limits($agency)['max_sites']);
        // $client exists only to prove counting still works with a real row.
        $this->assertSame(1, $this->entitlements()->usage($agency)['clients']);
    }
}
