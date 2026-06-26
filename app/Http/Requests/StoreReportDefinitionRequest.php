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
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['email'],
            'theme' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
            'theme.accent' => ['nullable', 'string', 'max:9'],
            'theme.density' => ['nullable', 'in:normal,compact'],
            'theme.nav' => ['nullable', 'array'],
            'theme.nav.position' => ['nullable', 'in:tabs,top,sidebar,hidden'],
            'theme.nav.style' => ['nullable', 'in:pill,underline,solid'],
            'theme.nav.collapsible' => ['nullable', 'boolean'],
            'pages' => ['nullable', 'array'],
            'pages.*.name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
