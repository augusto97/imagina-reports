<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a team member (name / role / optional new password). Privileged users only.
 */
final class UpdateTeamUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->role->isPrivileged();
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
