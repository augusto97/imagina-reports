<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Templates;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListTemplates extends ReadTool
{
    public function name(): string
    {
        return 'list_templates';
    }

    public function title(): string
    {
        return 'Plantillas de reporte';
    }

    public function description(): string
    {
        return 'Las plantillas de reporte de la agencia (el diseño que usa cada configuración de reporte), con cuántos bloques y páginas tiene cada una.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $templates = array_map(static fn (array $template): array => [
            ...self::pick($template, ['id', 'name', 'is_default', 'locale']),
            'bloques' => is_array($template['blocks'] ?? null) ? count($template['blocks']) : 0,
            'paginas' => is_array($template['pages'] ?? null) ? max(1, count($template['pages'])) : 1,
        ], $this->fetchList($context, 'report-templates'));

        return ToolResult::data(['plantillas' => $templates]);
    }
}
