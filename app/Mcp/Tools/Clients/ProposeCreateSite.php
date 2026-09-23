<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeCreateSite extends ProposalTool
{
    public function name(): string
    {
        return 'propose_create_site';
    }

    public function title(): string
    {
        return 'Crear sitio';
    }

    public function description(): string
    {
        return 'Propone añadir un sitio web a un cliente. No crea nada hasta que se confirme con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ClientsWrite;
    }

    protected function properties(): array
    {
        return [
            'client_id' => self::integer('Id del cliente al que pertenece.'),
            'name' => self::text('Nombre del sitio.'),
            'url' => self::text('URL completa, con https://.'),
            'hosting' => self::text('Hosting (opcional).'),
            'support_plan' => self::text('Plan de soporte contratado (opcional).'),
            'currency' => self::text('Moneda de sus reportes, p. ej. USD, EUR, COP (opcional).'),
            'plan_hours' => ['type' => 'number', 'description' => 'Horas de soporte incluidas al mes (opcional).'],
        ];
    }

    protected function required(): array
    {
        return ['client_id', 'name', 'url'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $clientId = $arguments->int('client_id');
        $client = $this->fetch($context, "clients/{$clientId}");
        $name = $arguments->string('name');
        $url = $arguments->string('url');

        $payload = array_filter([
            'client_id' => $clientId,
            'name' => $name,
            'url' => $url,
            'hosting' => $arguments->optionalString('hosting'),
            'support_plan' => $arguments->optionalString('support_plan'),
            'currency' => $arguments->optionalString('currency'),
            'plan_hours' => $arguments->optionalNumber('plan_hours'),
        ], static fn (mixed $value): bool => $value !== null);

        return new PreparedAction(
            "Crear el sitio «{$name}» ({$url}) para el cliente «".self::str($client['name'] ?? '').'».',
            $payload,
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $site = $this->api->call($context, 'POST', 'sites', $arguments->all())->orFail();

        return ToolResult::data(
            ['sitio' => self::pick($site->json, ['id', 'client_id', 'name', 'url'])],
            'Sitio creado. Para que tenga datos, conéctale fuentes (list_connectors).',
        );
    }
}
