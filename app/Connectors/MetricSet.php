<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * The normalized metric bag a connector returns (CLAUDE.md §7/§10.1): values keyed
 * by fully-qualified metric name (e.g. "ga4.sessions" scalar, "ga4.sessions_by_date"
 * series, "ga4.top_pages" table). Persisted as the normalized snapshot payload.
 *
 * Connectors never throw on API errors — they return partial() or failed() so the
 * report engine degrades gracefully.
 */
final readonly class MetricSet
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    private function __construct(
        public MetricSetStatus $status,
        public array $metrics,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function ok(array $metrics): self
    {
        return new self(MetricSetStatus::Ok, $metrics);
    }

    /**
     * Some metrics were retrieved; others failed.
     *
     * @param  array<string, mixed>  $metrics
     */
    public static function partial(array $metrics, string $error): self
    {
        return new self(MetricSetStatus::Partial, $metrics, $error);
    }

    public static function failed(string $error): self
    {
        return new self(MetricSetStatus::Failed, [], $error);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->metrics);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metrics[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->metrics);
    }

    public function isOk(): bool
    {
        return $this->status === MetricSetStatus::Ok;
    }

    public function isPartial(): bool
    {
        return $this->status === MetricSetStatus::Partial;
    }

    public function isFailed(): bool
    {
        return $this->status === MetricSetStatus::Failed;
    }

    /**
     * @return array{status: string, error: string|null, metrics: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'error' => $this->error,
            'metrics' => $this->metrics,
        ];
    }
}
