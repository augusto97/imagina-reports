<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's own account (CLAUDE.md §11.1): edit profile (name/email)
 * and change password. The current password is verified explicitly so it works
 * regardless of the auth guard; the `hashed` cast on User::$password hashes on save.
 */
final class AccountController extends Controller
{
    /** Update the signed-in user's own name and email (email unique across users). */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->forceFill([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
        ])->save();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'La contraseña actual no es correcta.',
            ]);
        }

        $user->forceFill(['password' => $request->string('password')->toString()])->save();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}
