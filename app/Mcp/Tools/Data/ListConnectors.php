<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Data;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListConnectors extends ReadTool
{
    public function name(): string
    {
        return 'list_connectors';
    }

    public function title(): string
    {
        return 'Tipos de fuente disponibles';
    }

    public function description(): string
    {
        return 'Las fuentes de datos que se pueden conectar y qué pide cada una. '
            .'Las marcadas «conexion_con_un_clic» (Google, Meta…) se conectan abriendo un enlace (get_connect_link); '
            .'las demás, con propose_add_data_source y los campos indicados.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $connectors = [];

        foreach ($this->fetchList($context, 'connectors') as $connector) {
            $fields = [];
            foreach (is_array($connector['config_schema'] ?? null) ? $connector['config_schema'] : [] as $field) {
                if (is_array($field)) {
                    $fields[] = self::pick($field, ['key', 'label', 'required', 'secret', 'help']);
                }
            }

            $connectors[] = [
                'tipo' => $connector['key'] ?? null,
                'nombre' => $connector['label'] ?? null,
                'conexion_con_un_clic' => ($connector['connect'] ?? null) !== null,
                'detecta_cuentas' => ($connector['lists_resources'] ?? false) === true,
                'campos' => $fields,
            ];
        }

        return ToolResult::data(['conectores' => $connectors]);
    }
}
