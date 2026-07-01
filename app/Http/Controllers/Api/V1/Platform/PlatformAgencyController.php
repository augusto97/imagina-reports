<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformAgencyRequest;
use App\Http\Requests\Platform\UpdatePlatformAgencyRequest;
use App\Models\Agency;
use App\Models\User;
use App\Services\Platform\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The platform panel's agency management (SaaS Fase 1): every agency across the platform,
 * with its plan + live usage, plus create / update (plan, status, overrides) and
 * impersonation ("enter as") for support. Platform-admin only (route middleware).
 */
final class PlatformAgencyController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function index(): JsonResponse
    {
        $agencies = Agency::query()->with('plan')->withCount('users')->orderByDesc('id')->get();

        return response()->json($agencies->map(fn (Agency $agency): array => $this->present($agency))->all());
    }

    public function store(StorePlatformAgencyRequest $request): JsonResponse
    {
        $name = $request->string('name')->toString();

        $agency = Agency::query()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'plan_id' => $request->integer('plan_id') ?: null,
            'status' => 'active',
        ]);

        User::query()->create([
            'agency_id' => $agency->id,
            'name' => $request->string('owner_name')->toString(),
            'email' => $request->string('owner_email')->toString(),
            'password' => Hash::make($request->string('owner_password')->toString()),
            'role' => UserRole::Owner,
        ]);

        return response()->json($this->present($agency->load('plan')), 201);
    }

    public function update(UpdatePlatformAgencyRequest $request, Agency $agency): JsonResponse
    {
        $agency->fill($request->validated())->save();

        return response()->json($this->present($agency->load('plan')));
    }

    /** Enter an agency for support (impersonation recorded on the admin's own row). */
    public function impersonate(Request $request, Agency $agency): JsonResponse
    {
        $this->admin($request)->update(['impersonating_agency_id' => $agency->id]);

        return response()->json(['impersonating' => $agency->id]);
    }

    public function stopImpersonate(Request $request): JsonResponse
    {
        $this->admin($request)->update(['impersonating_agency_id' => null]);

        return response()->json(['impersonating' => null]);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Agency $agency): array
    {
        return [
            'id' => $agency->id,
            'name' => $agency->name,
            'slug' => $agency->slug,
            'status' => $agency->status,
            'plan' => $agency->plan !== null ? ['id' => $agency->plan->id, 'name' => $agency->plan->name, 'slug' => $agency->plan->slug] : null,
            'plan_id' => $agency->plan_id,
            'plan_overrides' => $agency->plan_overrides,
            'limits' => $this->entitlements->limits($agency),
            'usage' => $this->entitlements->usage($agency),
            'created_at' => $agency->created_at?->toIso8601String(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'agency';
        $slug = $base;
        $i = 1;
        while (Agency::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
