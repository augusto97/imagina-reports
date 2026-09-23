<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListClients extends ReadTool
{
    public function name(): string
    {
        return 'list_clients';
    }

    public function title(): string
    {
        return 'Listar clientes';
    }

    public function description(): string
    {
        return 'Lista los clientes de la agencia con sus sitios. Úsala para encontrar el id de un cliente o sitio a partir de su nombre. '
            .'Filtra con «search» (parte del nombre del cliente o del sitio).';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return ['search' => self::text('Texto a buscar en el nombre del cliente, del sitio o en su URL (opcional).')];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $search = mb_strtolower($arguments->optionalString('search') ?? '');
        $sitesByClient = [];

        foreach ($this->fetchList($context, 'sites') as $site) {
            $clientId = self::intOrNull($site['client_id'] ?? null);
            if ($clientId !== null) {
                $sitesByClient[$clientId][] = self::pick($site, ['id', 'name', 'url', 'status']);
            }
        }

        $clients = [];
        foreach ($this->fetchList($context, 'clients') as $client) {
            $id = self::intOrNull($client['id'] ?? null);
            $sites = $id !== null ? ($sitesByClient[$id] ?? []) : [];
            $haystack = mb_strtolower(self::str($client['name'] ?? '').' '.implode(' ', array_map(
                static fn (array $site): string => self::str($site['name']).' '.self::str($site['url']),
                $sites,
            )));

            if ($search !== '' && ! str_contains($haystack, $search)) {
                continue;
            }

            $clients[] = [...self::pick($client, ['id', 'name', 'contact_email', 'locale']), 'sitios' => $sites];
        }

        return ToolResult::data(['clientes' => $clients, 'total' => count($clients)]);
    }
}
