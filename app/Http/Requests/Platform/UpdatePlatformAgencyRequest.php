<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update an agency's plan / status / per-agency overrides from the platform panel.
 */
final class UpdatePlatformAgencyRequest extends FormRequest
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
            'plan_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ir_plans', 'id')],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'plan_overrides' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
