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

final class ProposeUpdateClient extends ProposalTool
{
    private const FIELDS = ['name' => 'nombre', 'contact_email' => 'email', 'locale' => 'idioma', 'notes' => 'notas'];

    public function name(): string
    {
        return 'propose_update_client';
    }

    public function title(): string
    {
        return 'Editar cliente';
    }

    public function description(): string
    {
        return 'Propone cambiar el nombre, email, idioma o notas de un cliente. Solo cambia los campos que indiques. '
            .'No cambia nada hasta que se confirme con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ClientsWrite;
    }

    protected function properties(): array
    {
        return [
            'client_id' => self::integer('Id del cliente.'),
            'name' => self::text('Nuevo nombre (opcional).'),
            'contact_email' => self::text('Nuevo email de contacto (opcional).'),
            'locale' => self::text('Nuevo idioma: es, en o pt-BR (opcional).'),
            'notes' => self::text('Nuevas notas internas (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['client_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $clientId = $arguments->int('client_id');
        $current = $this->fetch($context, "clients/{$clientId}");

        $changes = [];
        $lines = [];
        foreach (self::FIELDS as $field => $label) {
            $value = $arguments->optionalString($field);
            if ($value !== null && $value !== ($current[$field] ?? null)) {
                $changes[$field] = $value;
                $lines[] = "- {$label}: «".self::str($current[$field] ?? '')."» → «{$value}»";
            }
        }

        if ($changes === []) {
            throw new ToolError('No hay cambios: indica al menos un campo con un valor distinto al actual.');
        }

        return new PreparedAction(
            'Editar el cliente «'.self::str($current['name'] ?? '')."»:\n".implode("\n", $lines),
            ['client_id' => $clientId, ...$changes],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $clientId = $arguments->int('client_id');
        $payload = $arguments->all();
        unset($payload['client_id']);

        $client = $this->api->call($context, 'PUT', "clients/{$clientId}", $payload)->orFail();

        return ToolResult::data(['cliente' => self::pick($client->json, ['id', 'name', 'contact_email', 'locale'])], 'Cliente actualizado.');
    }
}
