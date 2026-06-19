<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's own account (CLAUDE.md §11.1). Currently: change
 * password. The current password is verified explicitly so it works regardless of
 * the auth guard; the `hashed` cast on User::$password hashes the new value on save.
 */
final class AccountController extends Controller
{
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
