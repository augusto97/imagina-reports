<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeCreateClient extends ProposalTool
{
    public function name(): string
    {
        return 'propose_create_client';
    }

    public function title(): string
    {
        return 'Crear cliente';
    }

    public function description(): string
    {
        return 'Propone crear un cliente. No crea nada: devuelve una vista previa; aplícala con apply_proposal solo si la persona confirma.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ClientsWrite;
    }

    protected function properties(): array
    {
        return [
            'name' => self::text('Nombre del cliente.'),
            'contact_email' => self::text('Email de contacto (opcional).'),
            'locale' => self::text('Idioma de sus reportes: es, en o pt-BR (opcional, por defecto el de la agencia).'),
            'notes' => self::text('Notas internas (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['name'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $name = $arguments->string('name');
        $payload = array_filter([
            'name' => $name,
            'contact_email' => $arguments->optionalString('contact_email'),
            'locale' => $arguments->optionalString('locale'),
            'notes' => $arguments->optionalString('notes'),
        ], static fn (?string $value): bool => $value !== null);

        return new PreparedAction("Crear el cliente «{$name}».", $payload, $payload);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $client = $this->api->call($context, 'POST', 'clients', $arguments->all())->orFail();

        return ToolResult::data(['cliente' => self::pick($client->json, ['id', 'name', 'contact_email'])], 'Cliente creado.');
    }
}
