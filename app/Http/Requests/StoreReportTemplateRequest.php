<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBlocks;
use Illuminate\Foundation\Http\FormRequest;

final class StoreReportTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'blocks' => ['required', 'array'],
            'is_default' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:8'],
            'calculated_metrics' => ['nullable', 'array'],
            'calculated_metrics.*.key' => ['required_with:calculated_metrics', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'calculated_metrics.*.label' => ['nullable', 'string', 'max:120'],
            'calculated_metrics.*.formula' => ['required_with:calculated_metrics', 'string', 'max:500'],
            'theme' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
            'theme.accent' => ['nullable', 'string', 'max:9'],
            'theme.density' => ['nullable', 'in:normal,compact'],
        ];
    }
}
