<?php

declare(strict_types=1);

namespace App\Mcp\Proposals;

/**
 * A write, described but not done: the preview a person confirms, and the exact arguments
 * that will be replayed when they do.
 */
final readonly class PreparedAction
{
    /**
     * @param  string  $summary  One or a few lines a person can say yes or no to.
     * @param  array<string, mixed>  $arguments  Normalized arguments replayed on apply.
     * @param  array<string, mixed>  $details  Extra context for the preview (never secrets).
     */
    public function __construct(
        public string $summary,
        public array $arguments,
        public array $details = [],
    ) {}
}
