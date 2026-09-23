<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\Tools\DeleteTool;

final class ProposeDeleteDataSource extends DeleteTool
{
    public function name(): string
    {
        return 'propose_delete_data_source';
    }

    public function title(): string
    {
        return 'Eliminar una fuente';
    }

    public function description(): string
    {
        return 'Propone desconectar y eliminar una fuente de un sitio, junto con su historial sincronizado. '
            .'Los bloques que usaban sus datos quedarán vacíos. Irreversible: requiere confirmación con apply_proposal.';
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'data_source_id' => self::integer('Id de la fuente.'),
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'data_source_id'];
    }

    protected function describe(ToolArguments $arguments, McpContext $context): array
    {
        $siteId = $arguments->int('site_id');
        $sourceId = $arguments->int('data_source_id');
        $site = $this->site($context, $siteId);

        foreach ($this->fetchList($context, "sites/{$siteId}/data-sources") as $source) {
            if (self::intOrNull($source['id'] ?? null) === $sourceId) {
                return [
                    'path' => "data-sources/{$sourceId}",
                    'summary' => 'Eliminar la fuente '.self::str($source['type'] ?? '').' del sitio «'.self::str($site['name'] ?? '').'» y todo su historial sincronizado.',
                ];
            }
        }

        throw new ToolError('Esa fuente no existe en este sitio.');
    }
}
