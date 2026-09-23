<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeBackfillSite extends ProposalTool
{
    public function name(): string
    {
        return 'propose_backfill_site';
    }

    public function title(): string
    {
        return 'Traer meses anteriores';
    }

    public function description(): string
    {
        return 'Propone traer el historial de los meses anteriores de un sitio (de 1 a 24 meses), para poder comparar o generar reportes pasados. '
            .'Se ejecuta en segundo plano. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SourcesWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'months' => self::integer('Cuántos meses hacia atrás (1–24, por defecto 6).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $months = $arguments->optionalInt('months') ?? 6;

        if ($months < 1 || $months > 24) {
            throw new ToolError('«months» debe estar entre 1 y 24.');
        }

        $site = $this->site($context, $siteId);

        return new PreparedAction(
            "Traer los últimos {$months} meses de datos de todas las fuentes del sitio «".self::str($site['name'] ?? '').'».',
            ['site_id' => $siteId, 'months' => $months],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $this->api->call($context, 'POST', "sites/{$siteId}/backfill", ['months' => $arguments->int('months')])->orFail();

        return ToolResult::data(['en_cola' => true], 'Historial en camino. Puede tardar varios minutos; revisa get_sync_status.');
    }
}
