<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBlocks;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateReportTemplateRequest extends FormRequest
{
    use ValidatesBlocks;

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
            'blocks' => ['sometimes', 'array'],
            'is_default' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:8'],
            'calculated_metrics' => ['sometimes', 'nullable', 'array'],
            'calculated_metrics.*.key' => ['required_with:calculated_metrics', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'calculated_metrics.*.label' => ['nullable', 'string', 'max:120'],
            'calculated_metrics.*.formula' => ['required_with:calculated_metrics', 'string', 'max:500'],
            'theme' => ['sometimes', 'nullable', 'array'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'theme.accent' => ['nullable', 'string', 'max:9'],
            'theme.density' => ['nullable', 'in:normal,compact'],
            'pages' => ['sometimes', 'nullable', 'array'],
            'pages.*.name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
