<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a plan created/edited from the platform panel (SaaS Fase 1). On create,
 * `name` is required; on update, all fields are optional (PUT with a partial body).
 */
final class StorePlanRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'max_sites' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_data_sources' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_clients' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_reports_per_month' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'allowed_connectors' => ['sometimes', 'nullable', 'array'],
            'allowed_connectors.*' => ['string'],
            'features' => ['sometimes', 'nullable', 'array'],
            'monthly_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
