<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * Outcome of a connector's testConnection() (CLAUDE.md §7/§8): used by the admin
 * UI's "Test connection" action when configuring a data source.
 */
final readonly class ConnectionResult
{
    /**
     * @param  array<string, mixed>  $context  Non-sensitive diagnostic detail (never credentials).
     */
    private function __construct(
        public bool $successful,
        public string $message,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function success(string $message = 'Connection successful.', array $context = []): self
    {
        return new self(true, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function failure(string $message, array $context = []): self
    {
        return new self(false, $message, $context);
    }
}
