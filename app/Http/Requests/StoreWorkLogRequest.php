<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkLogRequest extends FormRequest
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
            'performed_at' => ['required', 'date'],
            'description' => ['required', 'string'],
            'screenshot_path' => ['nullable', 'string'],
        ];
    }
}
