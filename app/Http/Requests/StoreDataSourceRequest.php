<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DataSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDataSourceRequest extends FormRequest
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
            'type' => ['required', Rule::enum(DataSourceType::class)],
            'config' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
        ];
    }
}
