<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            // Second factor: absent on the first round-trip, supplied after the SPA is told
            // `two_factor_required`. Accepts a TOTP code or a recovery code.
            'two_factor_code' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
