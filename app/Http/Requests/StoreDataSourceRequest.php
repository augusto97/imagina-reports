<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DataSourceType;
use App\Http\Requests\Concerns\RequiresPrivilegedRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDataSourceRequest extends FormRequest
{
    use RequiresPrivilegedRole;

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
