<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Minimal text-completion contract for the AI report builder (CLAUDE.md §10.6).
 * Default implementation calls the Claude API; tests fake it (no live calls).
 */
interface AiClient
{
    public function complete(string $system, string $prompt): string;
}
