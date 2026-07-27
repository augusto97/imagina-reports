<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformUserRequest;
use App\Http\Requests\Platform\UpdatePlatformUserRequest;
use App\Models\Agency;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * People management across ANY agency, for the platform operator (§5). Agency owners manage
 * their own team, but support work needs the operator to be able to add a user, fix a role,
 * reset a forgotten password or remove someone — without impersonating first.
 *
 * Users are not agency-scoped by a global scope, so every action verifies the user really
 * belongs to the agency in the route.
 */
final class PlatformUserController extends Controller
{
    public function index(Agency $agency): JsonResponse
    {
        $users = User::query()->where('agency_id', $agency->id)->orderBy('name')->get();

        return response()->json($users->map(fn (User $user): array => $this->present($user))->all());
    }

    public function store(StorePlatformUserRequest $request, Agency $agency): JsonResponse
    {
        // forceFill: agency_id is a tenant boundary excluded from $fillable, set from the route.
        $user = new User;
        $user->forceFill([
            'agency_id' => $agency->id,
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'role' => UserRole::from($request->string('role')->toString()),
        ])->save();

        AuditLogger::record(AuditLogger::TEAM_CREATED, $user, "La plataforma añadió a {$user->name} ({$user->email}).", [], null, $agency->id);

        return response()->json($this->present($user), 201);
    }

    /** Change a user's name/role, or set a new password (support's "reset it for me" path). */
    public function update(UpdatePlatformUserRequest $request, Agency $agency, User $user): JsonResponse
    {
        $this->assertBelongsTo($user, $agency);

        $role = $request->has('role') ? UserRole::from($request->string('role')->toString()) : null;

        // Never leave an agency without an owner — it would lock everyone out of management.
        if ($role !== null && $user->role === UserRole::Owner && $role !== UserRole::Owner) {
            $this->assertNotLastOwner($agency, $user, 'No puedes quitar el último propietario de la agencia.');
        }

        if ($request->has('name')) {
            $user->name = $request->string('name')->toString();
        }

        if ($role !== null) {
            $user->role = $role;
        }

        $password = $request->string('password')->toString();
        if ($password !== '') {
            $user->password = Hash::make($password);
            AuditLogger::record(AuditLogger::ACCOUNT_PASSWORD_CHANGED, $user, "La plataforma restableció la contraseña de {$user->email}.", [], null, $agency->id);
        }

        $user->save();

        return response()->json($this->present($user));
    }

    public function destroy(Agency $agency, User $user): JsonResponse
    {
        $this->assertBelongsTo($user, $agency);
        $this->assertNotLastOwner($agency, $user, 'No puedes eliminar el último propietario de la agencia.');

        AuditLogger::record(AuditLogger::TEAM_DELETED, $user, "La plataforma eliminó a {$user->name} ({$user->email}).", [], null, $agency->id);

        $user->delete();

        return response()->json(null, 204);
    }

    /** Route-model binding isn't tenant-scoped for users — verify ownership explicitly. */
    private function assertBelongsTo(User $user, Agency $agency): void
    {
        abort_unless($user->agency_id === $agency->id, 404);
    }

    private function assertNotLastOwner(Agency $agency, User $user, string $message): void
    {
        if ($user->role !== UserRole::Owner) {
            return;
        }

        $owners = User::query()->where('agency_id', $agency->id)->where('role', UserRole::Owner->value)->count();

        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => $message]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
