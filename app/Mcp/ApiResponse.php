<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * The answer of an internal API call, with helpers to read it without trusting its shape.
 */
final readonly class ApiResponse
{
    /**
     * @param  array<array-key, mixed>  $json
     */
    public function __construct(
        public int $status,
        public array $json,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The API's own explanation of a failure — its validation messages when it gave any, so
     * the assistant can tell the person exactly which field to fix.
     */
    public function message(): string
    {
        $errors = $this->json['errors'] ?? null;
        if (is_array($errors)) {
            $messages = [];
            foreach ($errors as $list) {
                foreach (is_array($list) ? $list : [$list] as $message) {
                    if (is_string($message) && $message !== '') {
                        $messages[] = $message;
                    }
                }
            }
            if ($messages !== []) {
                return implode(' ', array_unique($messages));
            }
        }

        $message = $this->json['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return match ($this->status) {
            401 => 'La sesión del conector no es válida. Vuelve a conectar Imagina Reports.',
            403 => 'No tienes permiso para hacer esto en Imagina Reports.',
            404 => 'No existe o no pertenece a tu agencia.',
            402 => 'La cuenta de la agencia está suspendida por un pago pendiente.',
            429 => 'Demasiadas peticiones seguidas. Espera un minuto y vuelve a intentarlo.',
            default => "Imagina Reports respondió con un error (HTTP {$this->status}).",
        };
    }

    public function orFail(): self
    {
        if (! $this->ok()) {
            throw new ToolError($this->message());
        }

        return $this;
    }

    /**
     * The body as a list of records (for index endpoints).
     *
     * @return list<array<array-key, mixed>>
     */
    public function records(): array
    {
        $items = array_is_list($this->json) ? $this->json : ($this->json['data'] ?? []);
        $records = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $records[] = $item;
            }
        }

        return $records;
    }

    public function get(string $key): mixed
    {
        return data_get($this->json, $key);
    }
}
