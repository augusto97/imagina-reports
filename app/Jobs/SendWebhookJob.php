<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/**
 * Delivers a single outbound webhook (CLAUDE.md §8). The body is signed with an
 * HMAC-SHA256 over the exact JSON sent (header `X-Imagina-Signature: sha256=…`) when
 * the agency configured a secret, so consumers can verify authenticity. Queued and
 * retryable; a failing endpoint never affects the report pipeline.
 */
final class SendWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $url,
        public readonly string $event,
        public readonly array $payload,
        public readonly ?string $secret = null,
    ) {}

    public function handle(): void
    {
        $encoded = json_encode([
            'event' => $this->event,
            'sent_at' => Date::now()->toIso8601String(),
            'data' => $this->payload,
        ]);

        $body = $encoded === false ? '{}' : $encoded;

        $request = Http::timeout(15)->withBody($body, 'application/json');

        if ($this->secret !== null && $this->secret !== '') {
            $request = $request->withHeaders([
                'X-Imagina-Signature' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            ]);
        }

        $request->post($this->url);
    }
}
