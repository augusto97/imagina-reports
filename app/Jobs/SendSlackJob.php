<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Posts a formatted message to an agency's Slack incoming webhook (CLAUDE.md §8). Separate
 * from SendWebhookJob because Slack expects a `{text}` body (no HMAC), not our signed event
 * envelope. Queued + retryable; a failing Slack never affects the report pipeline.
 */
final class SendSlackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $url,
        public readonly string $text,
    ) {}

    public function handle(): void
    {
        Http::timeout(15)->post($this->url, ['text' => $this->text]);
    }
}
