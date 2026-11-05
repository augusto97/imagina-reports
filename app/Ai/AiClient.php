<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Minimal text-completion contract for the AI report builder (CLAUDE.md §10.6).
 * Default implementation calls the Claude API; tests fake it (no live calls).
 */
interface AiClient
{
    /**
     * @param  int  $maxTokens  Room for the answer (thinking included). Long outputs (a whole
     *                          report plan) need far more than a summary; the client streams them.
     */
    public function complete(string $system, string $prompt, int $maxTokens = 16000): string;
}
