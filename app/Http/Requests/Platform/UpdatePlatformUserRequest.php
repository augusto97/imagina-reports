<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a person's name/role, or set a new password, from the platform panel. The current
 * password is deliberately NOT required: this is the operator's "reset it for me" path.
 */
final class UpdatePlatformUserRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ];
    }
}
