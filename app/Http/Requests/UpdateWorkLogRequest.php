<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\WorkLogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits an existing work-log entry (CLAUDE.md §11.5). Every field is optional — only
 * the ones sent are changed. The screenshot is handled by the controller, never a
 * client-set path.
 */
final class UpdateWorkLogRequest extends FormRequest
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
            'performed_at' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'min:1'],
            'status' => ['sometimes', Rule::enum(WorkLogStatus::class)],
            'minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'screenshot' => ['sometimes', 'nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:4096'],
        ];
    }
}
