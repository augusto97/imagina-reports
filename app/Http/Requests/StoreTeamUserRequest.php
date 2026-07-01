<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite/create a team member for the current agency (SaaS Fase 1 — team management).
 * Only privileged users (owner/admin) may add teammates.
 */
final class StoreTeamUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('ir_users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }
}
