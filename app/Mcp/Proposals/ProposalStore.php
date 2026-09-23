<?php

declare(strict_types=1);

namespace App\Mcp\Proposals;

use App\Mcp\McpContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pending writes waiting for a person's confirmation.
 *
 * A proposal is single-use, expires after a few minutes, and belongs to the token that
 * created it — another token (even of the same agency) gets "not found", never a way to apply
 * someone else's change.
 */
final class ProposalStore
{
    public const TTL_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function put(McpContext $context, string $tool, array $arguments, string $summary): string
    {
        $id = 'prop_'.Str::lower(Str::random(24));

        Cache::put($this->key($id), [
            'token_id' => $context->token->getKey(),
            'tool' => $tool,
            'arguments' => $arguments,
            'summary' => $summary,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $id;
    }

    /**
     * Take (and consume) a proposal. Null when it doesn't exist, expired, was already applied,
     * or belongs to another token.
     *
     * @return array{tool: string, arguments: array<string, mixed>, summary: string}|null
     */
    public function take(McpContext $context, string $id): ?array
    {
        if (preg_match('/^prop_[a-z0-9]{24}$/', $id) !== 1) {
            return null;
        }

        // Pull, not get+forget: two concurrent applies of the same proposal must not both run.
        $stored = Cache::pull($this->key($id));

        if (! is_array($stored) || ($stored['token_id'] ?? null) !== $context->token->getKey()) {
            return null;
        }

        $tool = $stored['tool'] ?? null;
        $arguments = $stored['arguments'] ?? null;
        $summary = $stored['summary'] ?? null;

        if (! is_string($tool) || ! is_array($arguments) || ! is_string($summary)) {
            return null;
        }

        $normalized = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return ['tool' => $tool, 'arguments' => $normalized, 'summary' => $summary];
    }

    private function key(string $id): string
    {
        return 'mcp:proposal:'.$id;
    }
}
