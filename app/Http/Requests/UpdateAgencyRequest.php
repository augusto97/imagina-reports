<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates agency settings edited from the "Ajustes" screen (CLAUDE.md §11.1):
 * white-label branding + the agency's own Anthropic API key for the AI builder.
 */
final class UpdateAgencyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_locale' => ['nullable', 'string', 'in:es,en,pt_BR'],
            // Sent only when (re)setting the key; empty string clears it. Never returned.
            'anthropic_key' => ['nullable', 'string', 'max:255'],
            // Data retention in months; null/absent = keep forever. Sent → set/clear.
            'snapshot_retention_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
