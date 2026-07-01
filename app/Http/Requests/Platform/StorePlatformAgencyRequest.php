<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create an agency + its owner user from the platform panel (SaaS Fase 1).
 */
final class StorePlatformAgencyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'plan_id' => ['nullable', 'integer', Rule::exists('ir_plans', 'id')],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('ir_users', 'email')],
            'owner_password' => ['required', 'string', 'min:8'],
        ];
    }
}
