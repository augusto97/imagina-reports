<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CommentVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReportCommentRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', Rule::enum(CommentVisibility::class)],
        ];
    }
}
