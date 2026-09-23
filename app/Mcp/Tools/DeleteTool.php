<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;

/**
 * Shared shape of every deletion: look the thing up (so the preview names it and proves it
 * exists in this agency), say what goes with it, and state that it can't be undone.
 */
abstract class DeleteTool extends ProposalTool
{
    public function ability(): McpAbility
    {
        return McpAbility::Delete;
    }

    public function destructive(): bool
    {
        return true;
    }

    /**
     * @return array{path: string, summary: string}
     */
    abstract protected function describe(ToolArguments $arguments, McpContext $context): array;

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $described = $this->describe($arguments, $context);

        return new PreparedAction($described['summary'].' No se puede deshacer.', ['path' => $described['path']]);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $this->api->call($context, 'DELETE', $arguments->string('path'))->orFail();

        return ToolResult::data(['eliminado' => true], 'Eliminado.');
    }
}
