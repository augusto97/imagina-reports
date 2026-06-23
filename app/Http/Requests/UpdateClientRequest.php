<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateClientRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email'],
            'locale' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
