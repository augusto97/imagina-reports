<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Ai\AiModels;
use App\Http\Requests\Concerns\RequiresPrivilegedRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates agency settings edited from the "Ajustes" screen (CLAUDE.md §11.1):
 * white-label branding + the agency's own Anthropic API key for the AI builder.
 */
final class UpdateAgencyRequest extends FormRequest
{
    use RequiresPrivilegedRole;

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
            // The model used with the agency's own key; null/empty = the platform's default.
            'ai_model' => ['sometimes', 'nullable', 'string', Rule::in(AiModels::ids())],
            // Outbound webhook endpoints (integrations, §8). Sent → replace the list.
            'webhook_urls' => ['sometimes', 'array', 'max:20'],
            'webhook_urls.*' => ['string', 'url', 'max:2048'],
            // Optional signing secret; empty string clears it. Never returned in plaintext.
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Slack incoming webhook URL for alerts (§8). Empty string clears it.
            'slack_webhook_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            // How clients reach the agency from the portal and by replying to the report email.
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[+0-9 ()-]{6,40}$/'],
            'website' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            // «Pregúntale a tu reporte» in the portal (spends the agency's AI budget).
            'client_ai' => ['sometimes', 'boolean'],
            // Which emails the team gets (Etapa 5): a dropped connection, a client's question.
            'notify' => ['sometimes', 'array'],
            'notify.source_failures' => ['sometimes', 'boolean'],
            'notify.client_questions' => ['sometimes', 'boolean'],
        ];
    }
}
