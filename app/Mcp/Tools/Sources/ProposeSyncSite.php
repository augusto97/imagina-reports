<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeSyncSite extends ProposalTool
{
    public function name(): string
    {
        return 'propose_sync_site';
    }

    public function title(): string
    {
        return 'Sincronizar un sitio';
    }

    public function description(): string
    {
        return 'Propone traer de nuevo los datos de las fuentes de un sitio para un periodo (por defecto, el último mes completo). '
            .'Úsala cuando falten datos o después de conectar una fuente. Se ejecuta en segundo plano. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SourcesWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'month' => self::text('Mes AAAA-MM (opcional).'),
            'period_start' => self::date('Inicio del periodo (opcional).'),
            'period_end' => self::date('Fin del periodo (opcional).'),
            'data_source_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Solo estas fuentes (opcional; por defecto todas).'],
        ];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $site = $this->site($context, $siteId);
        $period = self::period($arguments->optionalString('month'), $arguments->optionalDate('period_start'), $arguments->optionalDate('period_end'));
        $only = $arguments->intList('data_source_ids');

        $which = $only === [] ? 'todas sus fuentes' : count($only).' fuente(s)';

        return new PreparedAction(
            'Sincronizar '.$which.' del sitio «'.self::str($site['name'] ?? '')."» para {$period['period_start']} → {$period['period_end']}.",
            ['site_id' => $siteId, ...$period, 'data_source_ids' => $only],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $payload = [
            'period_start' => $arguments->date('period_start'),
            'period_end' => $arguments->date('period_end'),
        ];
        $only = $arguments->intList('data_source_ids');
        if ($only !== []) {
            $payload['data_source_ids'] = $only;
        }

        $this->api->call($context, 'POST', "sites/{$siteId}/sync", $payload)->orFail();

        return ToolResult::data(['en_cola' => true], 'Sincronización en marcha. Revisa el resultado con get_sync_status en uno o dos minutos.');
    }
}
