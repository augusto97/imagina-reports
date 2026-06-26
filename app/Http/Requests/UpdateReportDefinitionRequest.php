<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBlocks;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateReportDefinitionRequest extends FormRequest
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
            'blocks' => ['sometimes', 'nullable', 'array'],
            'requested_metrics' => ['sometimes', 'nullable', 'array'],
            'template_id' => ['sometimes', 'nullable', 'integer'],
            'locale' => ['sometimes', 'string', 'max:8'],
            'recipients' => ['sometimes', 'nullable', 'array'],
            'recipients.*' => ['email'],
            'theme' => ['sometimes', 'nullable', 'array'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'theme.accent' => ['nullable', 'string', 'max:9'],
            'theme.density' => ['nullable', 'in:normal,compact'],
            'pages' => ['sometimes', 'nullable', 'array'],
            'pages.*.name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
