<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

/**
 * Maps a data-source type to its one-click connect provider (mirrors ConnectorRegistry).
 * A type without a provider simply has no "Connect" button — the manual configSchema form
 * is always available as the fallback.
 */
final class ConnectRegistry
{
    /** @var array<string, ConnectProvider> */
    private array $providers = [];

    public function register(ConnectProvider $provider): void
    {
        $this->providers[$provider->type()] = $provider;
    }

    public function has(string $type): bool
    {
        return isset($this->providers[$type]);
    }

    public function for(string $type): ?ConnectProvider
    {
        return $this->providers[$type] ?? null;
    }

    /**
     * @return array<string, ConnectProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
