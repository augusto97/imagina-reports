<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DataSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDataSourceRequest extends FormRequest
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
            'type' => ['sometimes', Rule::enum(DataSourceType::class)],
            'config' => ['nullable', 'array'],
            // Blank secret fields are ignored on update so existing credentials are kept
            // (the API never returns secrets back to the client — see DataSourceResource).
            'credentials' => ['nullable', 'array'],
        ];
    }
}
