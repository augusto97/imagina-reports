<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class TestDataSource extends ReadTool
{
    public function name(): string
    {
        return 'test_data_source';
    }

    public function title(): string
    {
        return 'Probar una fuente';
    }

    public function description(): string
    {
        return 'Comprueba en vivo si una fuente de datos conecta con su proveedor y devuelve el mensaje real del proveedor si falla. '
            .'No cambia nada. Es el botón «Probar» del panel.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return ['data_source_id' => self::integer('Id de la fuente (lo ves en get_site).')];
    }

    protected function required(): array
    {
        return ['data_source_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $id = $arguments->int('data_source_id');
        $response = $this->api->call($context, 'POST', "data-sources/{$id}/test")->orFail();

        return ToolResult::data([
            'conecta' => $response->get('successful') === true,
            'mensaje' => $response->get('message'),
        ]);
    }
}
