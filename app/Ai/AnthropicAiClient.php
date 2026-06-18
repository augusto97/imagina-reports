<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Claude API (Anthropic) implementation of AiClient. Model + key from
 * config('services.anthropic'). (Owner override of the originally-specced
 * gpt.imagina.cloud — see PROGRESS decisions.)
 */
final class AnthropicAiClient implements AiClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    public function complete(string $system, string $prompt): string
    {
        $key = config('services.anthropic.key');
        $model = config('services.anthropic.model');

        $response = Http::withHeaders([
            'x-api-key' => is_string($key) ? $key : '',
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
}
