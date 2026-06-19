<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Connectors\Support\ParsesValues;
use App\Jobs\SendWebhookJob;
use App\Models\Agency;

/**
 * Default WebhookDispatcher (CLAUDE.md §8). Reads the agency's webhook endpoints +
 * optional signing secret from `ir_agencies.settings` and queues one SendWebhookJob
 * per endpoint, so the actual HTTP POST is async and retryable and never blocks
 * report generation or delivery.
 */
final class HttpWebhookDispatcher implements WebhookDispatcher
{
    use ParsesValues;

    public function dispatch(int $agencyId, string $event, array $payload): void
    {
        $agency = Agency::query()->find($agencyId);

        if ($agency === null) {
            return;
        }

        $settings = $agency->settings ?? [];
        $secret = $this->toStr($settings['webhook_secret'] ?? '');

        foreach ($this->arrayOf($settings['webhook_urls'] ?? []) as $rawUrl) {
            $url = $this->toStr($rawUrl);

            if ($url !== '') {
                SendWebhookJob::dispatch($url, $event, $payload, $secret === '' ? null : $secret);
            }
        }
    }
}
