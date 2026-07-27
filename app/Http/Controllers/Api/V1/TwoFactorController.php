<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\TwoFactor\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * TOTP two-factor enrolment for the signed-in user (§11.1).
 *
 * Enrolment is two-step on purpose: `store` issues a secret but leaves 2FA OFF until
 * `confirm` proves the authenticator app produces a valid code — otherwise a misconfigured
 * app would lock the user out. Disabling requires the password, so a hijacked session can't
 * quietly strip the second factor.
 */
final class TwoFactorController extends Controller
{
    /** Start enrolment: issue a secret + provisioning URI (2FA stays off until confirmed). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => Totp::generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        $issuer = is_string(config('app.name')) ? config('app.name') : 'Imagina Reports';

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email, $issuer),
        ]);
    }

    /** Finish enrolment by proving the app is in sync; returns the recovery codes once. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $request->validate(['code' => ['required', 'string']]);

        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '' || ! Totp::verify($secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => 'El código no es válido. Comprueba la hora del teléfono e inténtalo de nuevo.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        AuditLogger::record(AuditLogger::ACCOUNT_2FA_ENABLED, $user, 'Activó la verificación en dos pasos.');

        return response()->json([
            'enabled' => true,
            // Shown once: the user must save them now (they're the way back in without the phone).
            'recovery_codes' => is_array($user->two_factor_recovery_codes) ? $user->two_factor_recovery_codes : [],
        ]);
    }

    /** Turn 2FA off. Requires the current password (a session alone must not be enough). */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogger::record(AuditLogger::ACCOUNT_2FA_DISABLED, $user, 'Desactivó la verificación en dos pasos.');

        return response()->json(['enabled' => false]);
    }
}
