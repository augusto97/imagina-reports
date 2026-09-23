<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;

/**
 * A write, in two steps: `prepare` validates and describes it (nothing changes), the person
 * confirms, then `apply_proposal` replays the prepared arguments through `apply`.
 *
 * `apply` re-reads everything it needs: minutes may have passed, and the confirmation must
 * never act on stale assumptions.
 */
abstract class ProposalTool extends Tool
{
    public function readOnly(): bool
    {
        return false;
    }

    abstract public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction;

    abstract public function apply(ToolArguments $arguments, McpContext $context): ToolResult;
}
