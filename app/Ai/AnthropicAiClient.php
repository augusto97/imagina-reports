<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Agency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Claude API (Anthropic) implementation of AiClient. The key and model come from AiSettings:
 * the current agency's own key (and its model choice) when it has one, otherwise the
 * platform's (super-admin panel, then .env). (Owner override of the originally-specced
 * gpt.imagina.cloud — see PROGRESS decisions.)
 */
final class AnthropicAiClient implements AiClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    public function __construct(private readonly TenantContext $tenant) {}

    public function complete(string $system, string $prompt): string
    {
        ['key' => $key, 'model' => $model, 'effort' => $effort] = AiSettings::for($this->agency());

        return $this->send($key ?? '', $model, $system, $prompt, 16000, $effort)['text'];
    }

    /**
     * One request with an explicit key + model. Never throws: a failure comes back as
     * `ok: false` with a message a person can act on (the key itself is never included).
     *
     * @return array{ok: bool, text: string, error: string|null}
     */
    public function send(string $key, string $model, string $system, string $prompt, int $maxTokens = 16000, string $effort = AiModels::DEFAULT_EFFORT): array
    {
        if ($key === '') {
            return ['ok' => false, 'text' => '', 'error' => 'No hay clave de IA configurada.'];
        }

        $extras = AiModels::extras($model, $effort);
        $headers = ['x-api-key' => $key, 'anthropic-version' => self::VERSION];
        if ($extras['beta'] !== null) {
            $headers['anthropic-beta'] = $extras['beta'];
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($effort === 'high' ? 180 : 120)
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    ...$extras['body'],
                ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'text' => '', 'error' => 'No se pudo conectar con Anthropic. Inténtalo de nuevo en un momento.'];
        }

        $json = $response->json();
        if (! $response->successful()) {
            return ['ok' => false, 'text' => '', 'error' => self::explain($response->status(), $json, $model)];
        }

        // A safety decline comes back as HTTP 200 with stop_reason «refusal»: no usable text.
        if (data_get($json, 'stop_reason') === 'refusal') {
            return ['ok' => false, 'text' => '', 'error' => 'La IA prefirió no responder a esta petición.'];
        }

        // With thinking on (the 5.x models), the first block can be a thinking block: join the text ones.
        $text = '';
        $content = data_get($json, 'content');
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return ['ok' => $text !== '', 'text' => $text, 'error' => $text === '' ? 'La IA respondió vacío.' : null];
    }

    private function agency(): ?Agency
    {
        $agencyId = $this->tenant->id();

        return $agencyId !== null ? Agency::query()->find($agencyId) : null;
    }

    private static function explain(int $status, mixed $json, string $model): string
    {
        $type = data_get($json, 'error.type');
        $message = data_get($json, 'error.message');
        $message = is_string($message) ? $message : '';

        return match (true) {
            $status === 401 || $type === 'authentication_error' => 'La clave no es válida. Revisa que la copiaste completa (empieza por sk-ant-).',
            $status === 403 || $type === 'permission_error' => 'Esta clave no tiene permiso para usar la API.',
            $status === 404 || $type === 'not_found_error' => 'Tu cuenta de Anthropic no tiene acceso al modelo «'.AiModels::label($model).'». Elige otro.',
            $status === 429 || $type === 'rate_limit_error' => 'Anthropic está limitando las peticiones de esta cuenta. Prueba en un minuto.',
            str_contains(strtolower($message), 'credit balance') => 'Tu cuenta de Anthropic no tiene saldo. Recárgala en console.anthropic.com → Billing.',
            $status >= 500 || $type === 'overloaded_error' => 'Anthropic no está respondiendo ahora mismo. Prueba en unos minutos.',
            default => 'Anthropic respondió con un error'.($message !== '' ? ': '.$message : ' ('.$status.').'),
        };
    }
}
