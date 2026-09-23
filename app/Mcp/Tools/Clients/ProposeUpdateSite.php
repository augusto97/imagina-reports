<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeUpdateSite extends ProposalTool
{
    private const FIELDS = [
        'name' => 'nombre',
        'url' => 'URL',
        'hosting' => 'hosting',
        'support_plan' => 'plan de soporte',
        'status' => 'estado',
        'currency' => 'moneda',
    ];

    public function name(): string
    {
        return 'propose_update_site';
    }

    public function title(): string
    {
        return 'Editar sitio';
    }

    public function description(): string
    {
        return 'Propone cambiar datos de un sitio (nombre, URL, hosting, plan de soporte, estado, moneda, horas del plan). '
            .'Solo cambia lo que indiques. No cambia nada hasta que se confirme con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ClientsWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'name' => self::text('Nuevo nombre (opcional).'),
            'url' => self::text('Nueva URL (opcional).'),
            'hosting' => self::text('Nuevo hosting (opcional).'),
            'support_plan' => self::text('Nuevo plan de soporte (opcional).'),
            'status' => self::text('Nuevo estado (opcional).'),
            'currency' => self::text('Nueva moneda (opcional).'),
            'plan_hours' => ['type' => 'number', 'description' => 'Nuevas horas de soporte al mes (opcional).'],
        ];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $current = $this->site($context, $siteId);

        $changes = [];
        $lines = [];
        foreach (self::FIELDS as $field => $label) {
            $value = $arguments->optionalString($field);
            if ($value !== null && $value !== ($current[$field] ?? null)) {
                $changes[$field] = $value;
                $lines[] = "- {$label}: «".self::str($current[$field] ?? '')."» → «{$value}»";
            }
        }

        $hours = $arguments->optionalNumber('plan_hours');
        if ($hours !== null) {
            $changes['plan_hours'] = $hours;
            $lines[] = '- horas del plan: '.self::str($current['plan_hours'] ?? '—')." → {$hours}";
        }

        if ($changes === []) {
            throw new ToolError('No hay cambios: indica al menos un campo con un valor distinto al actual.');
        }

        return new PreparedAction(
            'Editar el sitio «'.self::str($current['name'] ?? '')."»:\n".implode("\n", $lines),
            ['site_id' => $siteId, ...$changes],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $payload = $arguments->all();
        unset($payload['site_id']);

        $site = $this->api->call($context, 'PUT', "sites/{$siteId}", $payload)->orFail();

        return ToolResult::data(['sitio' => self::pick($site->json, ['id', 'name', 'url', 'status'])], 'Sitio actualizado.');
    }
}
