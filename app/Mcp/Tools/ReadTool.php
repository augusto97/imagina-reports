<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;

/**
 * A tool that answers directly. Reads, and the few harmless actions the panel runs on a single
 * click without asking (testing a connection, generating a connect link).
 */
abstract class ReadTool extends Tool
{
    abstract public function call(ToolArguments $arguments, McpContext $context): ToolResult;
}
