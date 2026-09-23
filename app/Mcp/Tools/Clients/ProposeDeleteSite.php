<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\Tools\DeleteTool;

final class ProposeDeleteSite extends DeleteTool
{
    public function name(): string
    {
        return 'propose_delete_site';
    }

    public function title(): string
    {
        return 'Eliminar sitio';
    }

    public function description(): string
    {
        return 'Propone eliminar un sitio junto con sus fuentes, datos sincronizados, configuraciones de reporte y reportes. '
            .'Irreversible: requiere confirmación con apply_proposal.';
    }

    protected function properties(): array
    {
        return ['site_id' => self::integer('Id del sitio.')];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    protected function describe(ToolArguments $arguments, McpContext $context): array
    {
        $siteId = $arguments->int('site_id');
        $site = $this->site($context, $siteId);
        $sources = count($this->fetchList($context, "sites/{$siteId}/data-sources"));
        $definitions = count(array_filter(
            $this->fetchList($context, 'report-definitions'),
            static fn (array $definition): bool => self::intOrNull($definition['site_id'] ?? null) === $siteId,
        ));

        return [
            'path' => "sites/{$siteId}",
            'summary' => 'Eliminar el sitio «'.self::str($site['name'] ?? '')."» y con él {$sources} fuente(s) de datos, "
                ."{$definitions} configuración(es) de reporte, todos sus reportes generados y su historial.",
        ];
    }
}
