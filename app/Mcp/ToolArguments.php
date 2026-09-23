<?php

declare(strict_types=1);

namespace App\Mcp;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Typed access to a tool call's arguments.
 *
 * Assistants send whatever JSON they like; every read here either returns the declared type or
 * throws a ToolError that names the argument, so a bad call comes back as an instruction the
 * assistant can fix ("Falta «site_id»") instead of a PHP error.
 */
final class ToolArguments
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values) {}

    public static function from(mixed $raw): self
    {
        if (! is_array($raw)) {
            return new self([]);
        }

        $values = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null && $this->values[$key] !== '';
    }

    public function string(string $key): string
    {
        $value = $this->optionalString($key);

        if ($value === null) {
            throw new ToolError("Falta el parámetro «{$key}».");
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new ToolError("El parámetro «{$key}» debe ser un texto.");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function int(string $key): int
    {
        $value = $this->optionalInt($key);

        if ($value === null) {
            throw new ToolError("Falta el parámetro «{$key}».");
        }

        return $value;
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new ToolError("El parámetro «{$key}» debe ser un número entero.");
    }

    public function optionalNumber(string $key): int|float|null
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $trimmed = is_string($value) ? trim($value) : null;
        if ($trimmed !== null && is_numeric($trimmed)) {
            return $trimmed + 0;
        }

        throw new ToolError("El parámetro «{$key}» debe ser un número.");
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = is_string($value) ? strtolower(trim($value)) : $value;

        return match ($normalized) {
            'true', '1', 1, 'si', 'sí', 'yes' => true,
            'false', '0', 0, 'no' => false,
            default => throw new ToolError("El parámetro «{$key}» debe ser verdadero o falso."),
        };
    }

    /**
     * A calendar date as `YYYY-MM-DD`.
     */
    public function date(string $key): string
    {
        $value = $this->optionalDate($key);

        if ($value === null) {
            throw new ToolError("Falta el parámetro «{$key}» (fecha AAAA-MM-DD).");
        }

        return $value;
    }

    public function optionalDate(string $key): ?string
    {
        $value = $this->optionalString($key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            throw new ToolError("El parámetro «{$key}» no es una fecha válida (usa AAAA-MM-DD).");
        }
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }

        if (! is_array($value)) {
            throw new ToolError("El parámetro «{$key}» debe ser una lista.");
        }

        $items = [];
        foreach ($value as $item) {
            if ((is_string($item) || is_int($item)) && trim((string) $item) !== '') {
                $items[] = trim((string) $item);
            }
        }

        return $items;
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        $items = [];
        foreach ($this->stringList($key) as $item) {
            if (preg_match('/^\d+$/', $item) !== 1) {
                throw new ToolError("El parámetro «{$key}» debe ser una lista de números.");
            }
            $items[] = (int) $item;
        }

        return $items;
    }

    /**
     * A list of objects, each exposed as its own ToolArguments.
     *
     * @return list<self>
     */
    public function objectList(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value) || $value === []) {
            throw new ToolError("El parámetro «{$key}» debe ser una lista con al menos un elemento.");
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new ToolError("Cada elemento de «{$key}» debe ser un objeto.");
            }
            $items[] = self::from($item);
        }

        return $items;
    }

    /**
     * A flat object of scalar values (connector config/credentials).
     *
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new ToolError("El parámetro «{$key}» debe ser un objeto clave → valor.");
        }

        $map = [];
        foreach ($value as $name => $item) {
            if (! is_string($name)) {
                continue;
            }
            if (is_string($item) || is_int($item) || is_float($item)) {
                $map[$name] = trim((string) $item);
            } elseif (is_bool($item)) {
                $map[$name] = $item ? '1' : '0';
            }
        }

        return $map;
    }
}
