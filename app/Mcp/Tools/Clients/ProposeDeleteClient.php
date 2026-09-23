<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Clients;

use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\Tools\DeleteTool;

final class ProposeDeleteClient extends DeleteTool
{
    public function name(): string
    {
        return 'propose_delete_client';
    }

    public function title(): string
    {
        return 'Eliminar cliente';
    }

    public function description(): string
    {
        return 'Propone eliminar un cliente. Solo es posible si ya no tiene sitios. Irreversible: requiere confirmación con apply_proposal.';
    }

    protected function properties(): array
    {
        return ['client_id' => self::integer('Id del cliente.')];
    }

    protected function required(): array
    {
        return ['client_id'];
    }

    protected function describe(ToolArguments $arguments, McpContext $context): array
    {
        $clientId = $arguments->int('client_id');
        $client = $this->fetch($context, "clients/{$clientId}");

        $sites = array_filter($this->fetchList($context, 'sites'), static fn (array $site): bool => self::intOrNull($site['client_id'] ?? null) === $clientId);
        if ($sites !== []) {
            throw new ToolError('Este cliente todavía tiene '.count($sites).' sitio(s). Elimínalos o reasígnalos antes.');
        }

        return ['path' => "clients/{$clientId}", 'summary' => 'Eliminar el cliente «'.self::str($client['name'] ?? '').'».'];
    }
}
