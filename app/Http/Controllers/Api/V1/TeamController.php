<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamUserRequest;
use App\Http\Requests\UpdateTeamUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Agency;
use App\Models\User;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

/**
 * Team management for an agency (SaaS Fase 1). Users aren't globally scoped, so every
 * action is explicitly bound to the current tenant. Only owner/admin may manage the team
 * (enforced by the FormRequests). Guards keep at least one owner and block self-deletion.
 */
final class TeamController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->where('agency_id', $this->tenant->id())->orderBy('name')->get(),
        );
    }

    public function store(StoreTeamUserRequest $request, Entitlements $entitlements): JsonResponse
    {
        $agency = Agency::query()->findOrFail($this->tenant->id());
        abort_unless($entitlements->canAddUser($agency), 403, 'Has alcanzado el límite de usuarios de tu plan. Mejora el plan para añadir más.');

        $user = User::query()->create([
            'agency_id' => $agency->id,
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'role' => UserRole::from($request->string('role')->toString()),
        ]);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(UpdateTeamUserRequest $request, User $user): UserResource
    {
        $this->authorizeSameAgency($user);
        $data = $request->validated();

        // Never demote the last owner (would lock the agency out of management).
        if (isset($data['role']) && $user->role === UserRole::Owner && $data['role'] !== UserRole::Owner->value) {
            abort_if($this->ownerCount() <= 1, 422, 'No puedes quitar el último propietario de la agencia.');
        }

        if (isset($data['name']) && is_string($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['role']) && is_string($data['role'])) {
            $user->role = UserRole::from($data['role']);
        }
        if (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return UserResource::make($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeSameAgency($user);

        $actingId = $request->user()?->getKey();
        abort_if($actingId !== null && $user->getKey() === $actingId, 422, 'No puedes eliminarte a ti mismo.');
        abort_if($user->role === UserRole::Owner && $this->ownerCount() <= 1, 422, 'No puedes eliminar el último propietario de la agencia.');

        $user->delete();

        return response()->json(null, 204);
    }

    /** Route-model binding isn't tenant-scoped for users, so verify ownership explicitly. */
    private function authorizeSameAgency(User $user): void
    {
        abort_unless($user->agency_id === $this->tenant->id(), 404);
    }

    private function ownerCount(): int
    {
        return User::query()->where('agency_id', $this->tenant->id())->where('role', UserRole::Owner->value)->count();
    }
}
