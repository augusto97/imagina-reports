<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\Update\UpdateManager;
use App\Support\TwoFactor\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-session auth for the first-party SPAs (CLAUDE.md §2 — Sanctum, cookie
 * sessions for own SPAs). The /api/v1 group is stateful (statefulApi), so a
 * successful login stores the session and the cookie then authenticates the rest
 * of the API.
 */
final class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        // Validate the password WITHOUT starting a session, so a correct password alone
        // never authenticates an account that also requires a second factor.
        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user = Auth::getLastAttempted();

        if ($user instanceof User && $user->hasTwoFactorEnabled()) {
            $code = $request->string('two_factor_code')->toString();

            // First round-trip: tell the SPA to ask for the code (no session yet).
            if ($code === '') {
                return response()->json(['two_factor_required' => true], 200);
            }

            $secret = is_string($user->two_factor_secret) ? $user->two_factor_secret : '';

            // A recovery code is accepted in place of the app code (lost phone) and burned.
            if (! Totp::verify($secret, $code) && ! $user->consumeRecoveryCode($code)) {
                throw ValidationException::withMessages([
                    'two_factor_code' => ['El código de verificación no es válido.'],
                ]);
            }
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return response()->json(['user' => $this->user($request)]);
    }

    public function me(Request $request, UpdateManager $manager): JsonResponse
    {
        return response()->json([
            'user' => [...$this->user($request), 'app_version' => $manager->currentVersion()],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, two_factor_enabled: bool, pending_email: string|null, is_platform_admin: bool, impersonating: int|null}
     */
    private function user(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        $impersonating = $user->is_platform_admin ? $user->impersonating_agency_id : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'pending_email' => $user->pending_email,
            'is_platform_admin' => $user->is_platform_admin,
            'impersonating' => $impersonating,
        ];
    }
}
