<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ai\AiClient;

/**
 * Stub AI client so AiReportBuilder tests never call the Claude API.
 */
final class FakeAiClient implements AiClient
{
    public ?string $lastPrompt = null;

    public function __construct(private readonly string $response) {}

    public function complete(string $system, string $prompt): string
    {
        $this->lastPrompt = $prompt;

        return $this->response;
    }
}
