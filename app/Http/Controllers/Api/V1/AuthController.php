<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
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
        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->user($request)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->user($request)]);
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
     * @return array{id: int, name: string, email: string, role: string}
     */
    private function user(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
        ];
    }
}
