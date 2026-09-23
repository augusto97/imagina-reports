<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class GetSite extends ReadTool
{
    public function name(): string
    {
        return 'get_site';
    }

    public function title(): string
    {
        return 'Ver un sitio';
    }

    public function description(): string
    {
        return 'Todo sobre un sitio: sus fuentes de datos (estado, última sincronización y el error real si fallan), '
            .'sus configuraciones de reporte (report_definition_id, que necesitas para generar reportes), destinatarios y envíos programados.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return ['site_id' => self::integer('Id del sitio (búscalo con list_clients).')];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $site = $this->site($context, $siteId);

        $sources = array_map(static fn (array $source): array => [
            ...self::pick($source, ['id', 'type', 'status', 'last_synced_at', 'last_error']),
            // Several accounts found after connecting: one has to be picked (propose_select_account).
            'cuentas_por_elegir' => $source['connect_options'] ?? null,
        ], $this->fetchList($context, "sites/{$siteId}/data-sources"));

        $definitions = [];
        $definitionIds = [];
        foreach ($this->fetchList($context, 'report-definitions') as $definition) {
            if (self::intOrNull($definition['site_id'] ?? null) === $siteId) {
                $definitions[] = self::pick($definition, ['id', 'name', 'template_id', 'locale', 'recipients']);
                $definitionIds[] = self::intOrNull($definition['id'] ?? null);
            }
        }

        $schedules = [];
        foreach ($this->fetchList($context, 'schedules') as $schedule) {
            if (in_array(self::intOrNull($schedule['report_definition_id'] ?? null), $definitionIds, true)) {
                $schedules[] = self::pick($schedule, ['id', 'report_definition_id', 'cadence', 'send_day', 'next_run_at']);
            }
        }

        return ToolResult::data([
            'sitio' => self::pick($site, ['id', 'client_id', 'name', 'url', 'hosting', 'support_plan', 'status', 'currency', 'plan_hours']),
            'fuentes' => $sources,
            'configuraciones_de_reporte' => $definitions,
            'envios_programados' => $schedules,
        ]);
    }
}
