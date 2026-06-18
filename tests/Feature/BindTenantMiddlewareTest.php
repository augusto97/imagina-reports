<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\BindTenant;
use App\Models\Agency;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class BindTenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_binds_the_tenant_from_the_authenticated_user(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);

        $tenant = app(TenantContext::class);
        $middleware = new BindTenant($tenant);

        $request = Request::create('/api/v1/user');
        $request->setUserResolver(fn (): User => $user);

        $middleware->handle($request, fn (Request $r): Response => new Response);

        $this->assertSame($agency->id, $tenant->id());
    }

    public function test_it_is_a_no_op_for_unauthenticated_requests(): void
    {
        $tenant = app(TenantContext::class);
        $middleware = new BindTenant($tenant);

        $request = Request::create('/api/v1/user');

        $middleware->handle($request, fn (Request $r): Response => new Response);

        $this->assertFalse($tenant->hasAgency());
    }
}
