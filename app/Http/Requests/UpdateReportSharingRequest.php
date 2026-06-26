<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReportVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a definition's sharing settings (CLAUDE.md §10/Etapa D): who can open the
 * report, the optional password, and the allowed embed domains.
 */
final class UpdateReportSharingRequest extends FormRequest
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
            'visibility' => ['required', Rule::enum(ReportVisibility::class)],
            // Sent only when (re)setting the password; null/absent leaves it unchanged.
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'embed_domains' => ['nullable', 'array', 'max:50'],
            'embed_domains.*' => ['string', 'max:255'],
        ];
    }
}
