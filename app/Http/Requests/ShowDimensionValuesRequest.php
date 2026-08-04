<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which dataset dimension the editor wants the real values of. Authorization is the route's
 * auth + tenant middleware (the site is resolved through the agency scope).
 */
final class ShowDimensionValuesRequest extends FormRequest
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
            'source' => ['required', 'string', 'max:64'],
            'metric' => ['required', 'string', 'max:128'],
            'dimension' => ['required', 'string', 'max:128'],
        ];
    }
}
