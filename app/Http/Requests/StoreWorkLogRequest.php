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
            // Optional — the quick-add UI defaults it to today.
            'performed_at' => ['nullable', 'date'],
            'description' => ['required', 'string'],
            // Time is OPTIONAL: some tasks just describe what was done.
            'minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'category' => ['nullable', 'string', 'max:255'],
            // Optional proof-of-work screenshot — stored on the public disk by the
            // controller (we never accept a client-set path, only an uploaded image).
            'screenshot' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:4096'],
        ];
    }
}
