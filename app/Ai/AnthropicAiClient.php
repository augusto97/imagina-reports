<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Agency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

/**
 * Claude API (Anthropic) implementation of AiClient. The API key is the current
 * agency's own key (configured from the UI, stored encrypted) when present, falling
 * back to config('services.anthropic.key'); the model comes from config. (Owner
 * override of the originally-specced gpt.imagina.cloud — see PROGRESS decisions.)
 */
final class AnthropicAiClient implements AiClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    public function __construct(private readonly TenantContext $tenant) {}

    public function complete(string $system, string $prompt): string
    {
        $key = $this->resolveKey();
        $model = config('services.anthropic.model');

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::VERSION,
        ])
            ->timeout(60)
            ->post(self::ENDPOINT, [
                'model' => is_string($model) ? $model : 'claude-sonnet-4-6',
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        $text = data_get($response->json(), 'content.0.text');

        return is_string($text) ? $text : '';
    }

    /**
     * The current agency's own key (preferred) or the server-wide config key.
     */
    private function resolveKey(): string
    {
        $agencyId = $this->tenant->id();

        if ($agencyId !== null) {
            $agencyKey = Agency::query()->find($agencyId)?->anthropicKey();

            if (is_string($agencyKey) && $agencyKey !== '') {
                return $agencyKey;
            }
        }

        $key = config('services.anthropic.key');

        return is_string($key) ? $key : '';
    }
}
