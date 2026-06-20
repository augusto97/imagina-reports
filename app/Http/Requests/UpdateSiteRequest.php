<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSiteRequest extends FormRequest
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
            'client_id' => ['sometimes', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url'],
            'hosting' => ['nullable', 'string', 'max:255'],
            'support_plan' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', Rule::in(array_keys(Site::CURRENCIES))],
            'plan_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }
}
