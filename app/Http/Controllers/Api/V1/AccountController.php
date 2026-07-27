<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's own account (CLAUDE.md §11.1): edit profile (name/email)
 * and change password. The current password is verified explicitly so it works
 * regardless of the auth guard; the `hashed` cast on User::$password hashes on save.
 */
final class AccountController extends Controller
{
    /**
     * Update the signed-in user's own name and email (email unique across users).
     *
     * Changing the email requires the current password: the login address is an account-
     * recovery factor, so a hijacked session must not be able to swap it and then take the
     * account over via "forgot password". Renaming alone needs no password.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $email = $request->string('email')->toString();
        $emailChanged = $email !== $user->email;

        if ($emailChanged && ! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Introduce tu contraseña actual para cambiar el email.',
            ]);
        }

        $user->forceFill(['name' => $request->string('name')->toString()])->save();

        // The new address is only applied once its owner confirms it (see verifyEmail).
        if ($emailChanged) {
            $token = Str::random(64);

            $user->forceFill([
                'pending_email' => $email,
                'pending_email_token' => $token,
                'pending_email_sent_at' => now(),
            ])->save();

            // Notify the NEW address — that mailbox is what we're proving ownership of.
            Notification::route('mail', $email)->notify(new VerifyPendingEmail($token));

            AuditLogger::record(AuditLogger::ACCOUNT_EMAIL_CHANGE_REQUESTED, $user, 'Solicitó cambiar su email de acceso.');
        }

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'pending_email' => $user->pending_email,
        ]);
    }

    /**
     * Confirm a pending email change from the link sent to the new address. Public (the user
     * may open it in another browser); the single-use token is the capability.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $user = User::query()
            ->withoutGlobalScopes()
            ->where('pending_email_token', $request->string('token')->toString())
            ->first();

        // Links expire so an old inbox message can't change the address much later.
        if (! $user instanceof User || $user->pending_email === null || $user->pending_email_sent_at?->lt(now()->subDay()) === true) {
            return response()->json(['message' => 'El enlace no es válido o ha caducado. Vuelve a solicitar el cambio.'], 422);
        }

        // The address may have been taken since the request was made.
        $taken = User::query()->withoutGlobalScopes()->where('email', $user->pending_email)->whereKeyNot($user->getKey())->exists();
        if ($taken) {
            return response()->json(['message' => 'Ese correo ya está en uso por otra cuenta.'], 422);
        }

        $newEmail = $user->pending_email;

        $user->forceFill([
            'email' => $newEmail,
            'email_verified_at' => now(),
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_sent_at' => null,
        ])->save();

        AuditLogger::record(AuditLogger::ACCOUNT_EMAIL_CHANGED, $user, 'Confirmó su nuevo email de acceso.', [], $user, $user->agency_id);

        return response()->json(['message' => 'Correo confirmado. Ya puedes iniciar sesión con él.', 'email' => $newEmail]);
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

        AuditLogger::record(AuditLogger::ACCOUNT_PASSWORD_CHANGED, $user, 'Cambió su contraseña.');

        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}
