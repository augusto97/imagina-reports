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
     * Drop blank webhook rows the UI may submit before validating the rest.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('webhook_urls'))) {
            $this->merge([
                'webhook_urls' => array_values(array_filter(
                    array_map(static fn ($url): string => is_string($url) ? trim($url) : '', $this->input('webhook_urls')),
                    static fn (string $url): bool => $url !== '',
                )),
            ]);
        }
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
            // Outbound webhook endpoints (integrations, §8). Sent → replace the list.
            'webhook_urls' => ['sometimes', 'array', 'max:20'],
            'webhook_urls.*' => ['string', 'url', 'max:2048'],
            // Optional signing secret; empty string clears it. Never returned in plaintext.
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
