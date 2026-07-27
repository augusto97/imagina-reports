<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformAgencyRequest;
use App\Http\Requests\Platform\UpdatePlatformAgencyRequest;
use App\Models\Agency;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $agencies = Agency::query()->with('plan')->orderByDesc('id')->get();

        // Batch usage for all agencies in a handful of grouped queries (PERF-4) instead of
        // 5 counts per row.
        $usage = $this->entitlements->usageForAll();

        return response()->json($agencies->map(fn (Agency $agency): array => $this->present($agency, $usage[$agency->id] ?? null))->all());
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

        // forceFill: agency_id is excluded from $fillable (audit SEC); set here from the
        // just-created agency, not from request input.
        $owner = new User;
        $owner->forceFill([
            'agency_id' => $agency->id,
            'name' => $request->string('owner_name')->toString(),
            'email' => $request->string('owner_email')->toString(),
            'password' => Hash::make($request->string('owner_password')->toString()),
            'role' => UserRole::Owner,
        ])->save();

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
        // forceFill: impersonating_agency_id is excluded from $fillable (audit SEC).
        $this->admin($request)->forceFill(['impersonating_agency_id' => $agency->id])->save();

        return response()->json(['impersonating' => $agency->id]);
    }

    public function stopImpersonate(Request $request): JsonResponse
    {
        $this->admin($request)->forceFill(['impersonating_agency_id' => null])->save();

        return response()->json(['impersonating' => null]);
    }

    /**
     * Everything the operator needs about one agency: usage vs limits, its people, and its
     * subscription. Backs the agency detail panel (the super-admin's main working view).
     */
    public function show(Agency $agency): JsonResponse
    {
        $users = User::query()
            ->where('agency_id', $agency->id)
            ->orderBy('name')
            ->get()
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();

        $subscription = Subscription::query()->where('agency_id', $agency->id)->latest()->first();

        return response()->json([
            ...$this->present($agency->load('plan')),
            'users' => $users,
            'subscription' => $subscription === null ? null : [
                'provider' => $subscription->provider,
                'status' => $subscription->status->value,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Permanently delete an agency and everything under it. This is the operator's escape
     * hatch for the "an account can never be removed" problem — deliberately gated behind
     * retyping the agency name.
     *
     * Recorded to the application log, not to ir_audit_logs: that table is agency-scoped and
     * cascades with the agency, so a row written here would vanish with the very thing it
     * documents. The app log is the only trail that outlives the tenant.
     */
    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        $request->validate(['confirm_name' => ['required', 'string']]);

        if (trim($request->string('confirm_name')->toString()) !== trim($agency->name)) {
            throw ValidationException::withMessages(['confirm_name' => 'Escribe el nombre exacto de la agencia para confirmar.']);
        }

        $admin = $this->admin($request);

        Log::warning('Platform admin deleted an agency and all of its data.', [
            'agency_id' => $agency->id,
            'agency_name' => $agency->name,
            'actor_id' => $admin->getKey(),
            'actor_email' => $admin->email,
            'ip' => $request->ip(),
        ]);

        // impersonating_agency_id has no FK, so it would be left pointing at a dead agency.
        User::query()->where('impersonating_agency_id', $agency->id)
            ->update(['impersonating_agency_id' => null]);

        $agency->delete();

        return response()->json(['message' => 'Agencia eliminada.']);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array{sites: int, data_sources: int, clients: int, users: int, reports_this_month: int}|null  $usage
     *                                                                                                               Precomputed usage (from the batched index query); null falls back to a
     *                                                                                                               single-agency lookup for store/update.
     * @return array<string, mixed>
     */
    private function present(Agency $agency, ?array $usage = null): array
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
            'usage' => $usage ?? $this->entitlements->usage($agency),
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
