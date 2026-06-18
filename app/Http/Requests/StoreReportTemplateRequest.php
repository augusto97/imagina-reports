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
        ];
    }
}
