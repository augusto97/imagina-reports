<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * "Forgot password" flow (§11.1). Public + throttled: `forgot` emails a signed reset
 * link (the URL points at the SPA reset screen — see AppServiceProvider), `reset`
 * consumes the token and sets the new password. Both always answer generically so the
 * endpoint can't be used to probe which emails exist.
 */
final class PasswordResetController extends Controller
{
    private const GENERIC = 'Si el correo existe, te enviamos un enlace para restablecer la contraseña.';

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Never reveal whether the address is registered.
        return response()->json(['message' => self::GENERIC]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                Event::dispatch(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            return response()->json(['message' => 'El enlace de restablecimiento no es válido o ha caducado. Solicítalo de nuevo.'], 422);
        }

        return response()->json(['message' => 'Contraseña restablecida. Ya puedes iniciar sesión.']);
    }
}
