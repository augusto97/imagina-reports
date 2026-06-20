<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSiteRequest extends FormRequest
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
            'client_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'hosting' => ['nullable', 'string', 'max:255'],
            'support_plan' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', Rule::in(array_keys(Site::CURRENCIES))],
            'plan_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }
}
