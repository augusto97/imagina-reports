<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class DiscoverAccounts extends ReadTool
{
    public function name(): string
    {
        return 'discover_accounts';
    }

    public function title(): string
    {
        return 'Detectar cuentas de una fuente';
    }

    public function description(): string
    {
        return 'Pregunta al proveedor qué cuentas o propiedades alcanza una fuente ya conectada (el botón «Detectar cuentas»). '
            .'Si hay una sola, queda seleccionada; si hay varias, devuelve la lista para elegir con propose_select_account.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SourcesWrite;
    }

    public function readOnly(): bool
    {
        return false;
    }

    protected function properties(): array
    {
        return ['data_source_id' => self::integer('Id de la fuente.')];
    }

    protected function required(): array
    {
        return ['data_source_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $id = $arguments->int('data_source_id');
        $response = $this->api->call($context, 'POST', "data-sources/{$id}/discover")->orFail();

        return ToolResult::data([
            'correcto' => $response->get('successful') === true,
            'mensaje' => $response->get('message'),
            'cuentas_por_elegir' => $response->get('data_source.connect_options'),
        ]);
    }
}
