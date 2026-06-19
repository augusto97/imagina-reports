<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReportDefinitionRequest extends FormRequest
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
            'site_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer'],
            'blocks' => ['nullable', 'array'],
            'requested_metrics' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:8'],
        ];
    }
}
