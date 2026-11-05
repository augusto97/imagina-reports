<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Agency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Claude API (Anthropic) implementation of AiClient. The key, model choice and effort come from
 * AiSettings (the agency's own key and choices, or the platform's); the choice is resolved to a
 * real model against Anthropic's live list (AiModelCatalog), and what each request carries
 * (effort level, server-side fallback) comes from that model's reported capabilities.
 * (Owner override of the originally-specced gpt.imagina.cloud — see PROGRESS decisions.)
 */
final class AnthropicAiClient implements AiClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    /** Why the last complete() came back empty (shown by callers that can explain it). */
    private ?string $lastError = null;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AiModelCatalog $catalog,
    ) {}

    public function complete(string $system, string $prompt, int $maxTokens = 16000): string
    {
        ['key' => $key, 'model' => $choice, 'effort' => $effort] = AiSettings::for($this->agency());
        $result = $this->send($key ?? '', $choice, $system, $prompt, $maxTokens, $effort);
        $this->lastError = $result['ok'] ? null : $result['error'];

        return $result['text'];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * One request with an explicit key + model choice. Never throws: a failure comes back as
     * `ok: false` with a message a person can act on (the key itself is never included).
     *
     * @return array{ok: bool, text: string, error: string|null, model: string, label: string, effort: string|null}
     */
    public function send(string $key, string $choice, string $system, string $prompt, int $maxTokens = 16000, string $effort = AiModels::DEFAULT_EFFORT): array
    {
        if ($key === '') {
            return ['ok' => false, 'text' => '', 'error' => 'No hay clave de IA configurada.', 'model' => '', 'label' => '', 'effort' => null];
        }

        $resolved = AiModelCatalog::resolve($choice, $this->catalog->list($key)['models']);
        $model = $resolved['model'];
        // Only what this model says it accepts: an effort level it supports, the fallback if offered.
        $level = $model !== null ? AiModels::effortFor($effort, $model['efforts']) : null;
        $fallbacks = $model !== null && $model['fallbacks'];

        // Never ask for more room than the model has (Anthropic lists each model's output ceiling).
        $ceiling = $model['max_output'] ?? null;
        $maxTokens = is_int($ceiling) && $ceiling > 0 ? min($maxTokens, $ceiling) : $maxTokens;

        $headers = ['x-api-key' => $key, 'anthropic-version' => self::VERSION];
        $body = [
            'model' => $resolved['id'],
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($level !== null) {
            $body['output_config'] = ['effort' => $level];
        }
        if ($fallbacks) {
            $headers['anthropic-beta'] = AiModels::FALLBACK_BETA;
            $body['fallbacks'] = 'default';
        }

        $meta = ['model' => $resolved['id'], 'label' => $resolved['label'], 'effort' => $level];

        // Long answers (a whole report plan) are streamed: a single long-held request is what
        // timeouts cut, and streaming keeps the connection busy while the model thinks and writes.
        $streamed = $maxTokens > 16000;

        try {
            $request = Http::withHeaders($headers);
            $response = $streamed
                ? $request->withOptions(['stream' => true, 'read_timeout' => 300])->timeout(900)->post(self::ENDPOINT, [...$body, 'stream' => true])
                : $request->timeout(in_array($level, ['high', 'xhigh', 'max'], true) ? 180 : 120)->post(self::ENDPOINT, $body);
        } catch (ConnectionException) {
            return ['ok' => false, 'text' => '', 'error' => 'No se pudo conectar con Anthropic. Inténtalo de nuevo en un momento.', ...$meta];
        }

        if (! $response->successful()) {
            if ($response->status() === 404) {
                $this->catalog->forget($key); // a retired model: read the list again next time
            }

            return ['ok' => false, 'text' => '', 'error' => self::explain($response->status(), $response->json(), $resolved['label']), ...$meta];
        }

        ['text' => $text, 'stop' => $stop, 'error' => $streamError] = $streamed ? $this->readStream($response) : $this->readJson($response->json());
        if ($streamError !== null) {
            return ['ok' => false, 'text' => '', 'error' => $streamError, ...$meta];
        }

        // A safety decline comes back as HTTP 200 with stop_reason «refusal»: no usable text.
        if ($stop === 'refusal') {
            return ['ok' => false, 'text' => '', 'error' => 'La IA prefirió no responder a esta petición.', ...$meta];
        }

        // Ran out of room mid-answer: the text is cut (a JSON layout would be unusable).
        if ($stop === 'max_tokens') {
            return ['ok' => false, 'text' => $text, 'error' => 'La respuesta de la IA quedó incompleta porque era demasiado larga.', ...$meta];
        }

        return ['ok' => $text !== '', 'text' => $text, 'error' => $text === '' ? 'La IA respondió vacío.' : null, ...$meta];
    }

    /**
     * A whole (non-streamed) answer: with thinking on, the first block can be a thinking block,
     * so only the text blocks are joined.
     *
     * @return array{text: string, stop: string|null, error: string|null}
     */
    private function readJson(mixed $json): array
    {
        $text = '';
        $content = data_get($json, 'content');
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $stop = data_get($json, 'stop_reason');

        return ['text' => $text, 'stop' => is_string($stop) ? $stop : null, 'error' => null];
    }

    /**
     * A streamed answer (server-sent events): the text deltas joined, how it stopped, and an
     * error event if Anthropic sent one mid-stream (e.g. overloaded).
     *
     * @return array{text: string, stop: string|null, error: string|null}
     */
    private function readStream(Response $response): array
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $text = '';
        $stop = null;
        $error = null;

        $handle = function (string $line) use (&$text, &$stop, &$error): void {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                return;
            }
            $event = json_decode(trim(substr($line, 5)), true);
            if (! is_array($event)) {
                return;
            }
            $type = $event['type'] ?? null;
            if ($type === 'content_block_delta' && data_get($event, 'delta.type') === 'text_delta' && is_string(data_get($event, 'delta.text'))) {
                $text .= data_get($event, 'delta.text');
            } elseif ($type === 'message_delta' && is_string(data_get($event, 'delta.stop_reason'))) {
                $stop = data_get($event, 'delta.stop_reason');
            } elseif ($type === 'error') {
                $error = self::explain(0, $event, '');
            }
        };

        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($position = strpos($buffer, "\n")) !== false) {
                $handle(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);
            }
        }
        if ($buffer !== '') {
            $handle($buffer);
        }

        return ['text' => $text, 'stop' => is_string($stop) ? $stop : null, 'error' => $error];
    }

    /** A message a person can act on, from an Anthropic error response (never includes the key). */
    public static function explain(int $status, mixed $json, string $modelLabel): string
    {
        $type = data_get($json, 'error.type');
        $message = data_get($json, 'error.message');
        $message = is_string($message) ? $message : '';

        return match (true) {
            $status === 401 || $type === 'authentication_error' => 'La clave no es válida. Revisa que la copiaste completa (empieza por sk-ant-).',
            $status === 403 || $type === 'permission_error' => 'Esta clave no tiene permiso para usar la API.',
            $status === 404 || $type === 'not_found_error' => $modelLabel !== ''
                ? 'Tu cuenta de Anthropic no tiene acceso al modelo «'.$modelLabel.'». Elige otro o «el más reciente».'
                : 'Anthropic no encontró lo que se pidió.',
            $status === 429 || $type === 'rate_limit_error' => 'Anthropic está limitando las peticiones de esta cuenta. Prueba en un minuto.',
            str_contains(strtolower($message), 'credit balance') => 'Tu cuenta de Anthropic no tiene saldo. Recárgala en console.anthropic.com → Billing.',
            $status >= 500 || $type === 'overloaded_error' => 'Anthropic no está respondiendo ahora mismo. Prueba en unos minutos.',
            default => 'Anthropic respondió con un error'.($message !== '' ? ': '.$message : ' ('.$status.').'),
        };
    }

    private function agency(): ?Agency
    {
        $agencyId = $this->tenant->id();

        return $agencyId !== null ? Agency::query()->find($agencyId) : null;
    }
}
