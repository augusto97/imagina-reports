<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * Outbound webhook contract (CLAUDE.md §8): emits events (`report.generated`,
 * `report.sent`, `anomaly.detected`) to an agency's configured endpoints. Behind an
 * interface so the HTTP implementation can be swapped/faked in tests.
 */
interface WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(int $agencyId, string $event, array $payload): void;
}
